<?php

use App\Services\Agents\AgentTurn;
use App\Services\Agents\AgentTurnParser;

function parseTurn(array|string $payload): AgentTurn
{
    return (new AgentTurnParser)->parse(is_array($payload) ? json_encode($payload) : $payload);
}

function turnPayload(array $overrides = []): array
{
    return $overrides + ['reply' => 'Hola', 'action' => 'none', 'lead' => null];
}

test('lee un turno estructurado válido', function () {
    $turn = parseTurn(turnPayload([
        'reply' => '  ¡Claro! Te transfiero.  ',
        'action' => 'request_human',
        'lead' => ['name' => 'Alejandro', 'phone' => '300 123 4567', 'email' => 'ale@example.com', 'interest' => 'Plan empresarial'],
    ]));

    expect($turn->reply)->toBe('¡Claro! Te transfiero.')
        ->and($turn->requestsHuman())->toBeTrue()
        ->and($turn->structured)->toBeTrue()
        ->and($turn->lead)->toBe(['name' => 'Alejandro', 'phone' => '3001234567', 'email' => 'ale@example.com', 'interest' => 'Plan empresarial']);
});

test('acepta el JSON envuelto en un bloque de código', function () {
    $turn = parseTurn("```json\n".json_encode(turnPayload(['reply' => 'Con fences']))."\n```");

    expect($turn->reply)->toBe('Con fences')->and($turn->structured)->toBeTrue();
});

test('si el modelo devuelve texto plano se usa tal cual y no se actúa', function () {
    $turn = parseTurn('Hola, ¿en qué te ayudo?');

    expect($turn->reply)->toBe('Hola, ¿en qué te ayudo?')
        ->and($turn->structured)->toBeFalse()
        ->and($turn->action)->toBe('none')
        ->and($turn->lead)->toBeNull();
});

test('un JSON sin reply no se le muestra al cliente como texto crudo', function () {
    expect(parseTurn(['action' => 'request_human', 'lead' => null])->reply)->toBe('')
        ->and(parseTurn('[1,2,3]')->reply)->toBe('');
});

test('una acción desconocida se ignora', function () {
    foreach (['borrar_todo', 'REQUEST_HUMAN', 5, null, ['request_human']] as $action) {
        expect(parseTurn(turnPayload(['action' => $action]))->action)->toBe('none');
    }
});

test('normaliza teléfonos y descarta los inválidos', function () {
    $phone = fn ($raw) => parseTurn(turnPayload(['lead' => ['name' => null, 'phone' => $raw, 'email' => 'a@b.co', 'interest' => null]]))->lead['phone'];

    expect($phone('+57 300-123 4567'))->toBe('+573001234567')
        ->and($phone('(601) 234 5678'))->toBe('6012345678')
        ->and($phone('12345'))->toBeNull()                    // muy corto
        ->and($phone('1234567890123456'))->toBeNull()          // muy largo
        ->and($phone('no tengo'))->toBeNull()
        ->and($phone(3001234567))->toBeNull();                 // no es texto
});

test('valida y pasa a minúsculas el correo', function () {
    $email = fn ($raw) => parseTurn(turnPayload(['lead' => ['name' => null, 'phone' => '3001234567', 'email' => $raw, 'interest' => null]]))->lead['email'];

    expect($email('  ALE@Example.COM '))->toBe('ale@example.com')
        ->and($email('no-es-correo'))->toBeNull()
        ->and($email('a@b'))->toBeNull()
        ->and($email(str_repeat('a', 250).'@example.com'))->toBeNull();
});

test('sin teléfono ni correo válidos no hay lead', function () {
    expect(parseTurn(turnPayload(['lead' => ['name' => 'Ana', 'phone' => null, 'email' => null, 'interest' => 'Algo']]))->lead)->toBeNull()
        ->and(parseTurn(turnPayload(['lead' => ['name' => 'Ana', 'phone' => '123', 'email' => 'mal', 'interest' => null]]))->lead)->toBeNull()
        ->and(parseTurn(turnPayload(['lead' => 'Ana']))->lead)->toBeNull()
        ->and(parseTurn(turnPayload(['lead' => []]))->lead)->toBeNull();
});

test('limpia caracteres de control y recorta nombre e interés', function () {
    $lead = parseTurn(turnPayload(['lead' => [
        'name' => "  Ana\n\tMaría\x00  ",
        'phone' => '3001234567',
        'email' => null,
        'interest' => str_repeat('x', 600),
    ]]))->lead;

    expect($lead['name'])->toBe('Ana María')
        ->and(mb_strlen($lead['interest']))->toBe(500)
        ->and(mb_strlen(parseTurn(turnPayload(['lead' => ['name' => str_repeat('n', 300), 'phone' => '3001234567', 'email' => null, 'interest' => null]]))->lead['name']))->toBe(120);
});

test('un campo que no es texto se trata como ausente', function () {
    $lead = parseTurn(turnPayload(['lead' => ['name' => ['x'], 'phone' => '3001234567', 'email' => 5, 'interest' => true]]))->lead;

    expect($lead)->toBe(['name' => null, 'phone' => '3001234567', 'email' => null, 'interest' => null]);
});

test('el esquema es válido para salidas estructuradas: todo objeto cierra y exige todas sus propiedades', function () {
    $check = function (array $node) use (&$check) {
        if (($node['type'] ?? null) === 'object') {
            expect($node['additionalProperties'])->toBeFalse()
                ->and($node['required'])->toEqualCanonicalizing(array_keys($node['properties']));
        }
        foreach ($node['properties'] ?? [] as $child) {
            $check($child);
        }
        foreach ($node['anyOf'] ?? [] as $child) {
            $check($child);
        }
    };

    $schema = AgentTurnParser::schema();
    $check($schema);

    expect($schema['required'])->toBe(['reply', 'action', 'lead'])
        ->and($schema['properties']['action']['enum'])->toBe(['none', 'request_human']);
});
