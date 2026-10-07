<?php

use App\Services\Knowledge\TextChunker;

test('un texto corto queda en un solo fragmento', function () {
    expect((new TextChunker(1000, 100))->chunk("Hola.\n\nSomos una barbería."))->toBe(['Hola. Somos una barbería.']);
});

test('un texto vacío o solo con espacios no genera fragmentos', function () {
    expect((new TextChunker)->chunk("  \n\n \t "))->toBe([]);
});

test('parte textos largos respetando el tamaño máximo más el solapamiento', function () {
    $text = collect(range(1, 80))->map(fn ($i) => "Esta es la oración número $i del documento de prueba.")->implode(' ');

    $chunks = (new TextChunker(300, 60))->chunk($text);

    expect(count($chunks))->toBeGreaterThan(5);
    foreach ($chunks as $chunk) {
        expect(mb_strlen($chunk))->toBeLessThanOrEqual(300 + 60);
    }
});

test('no se pierde ninguna oración al trocear', function () {
    $sentences = collect(range(1, 40))->map(fn ($i) => "Oración única $i termina aquí.");
    $chunks = (new TextChunker(250, 50))->chunk($sentences->implode(' '));

    $joined = implode(' ', $chunks);
    foreach ($sentences as $sentence) {
        expect($joined)->toContain($sentence);
    }
});

test('fragmentos contiguos comparten solapamiento', function () {
    $text = collect(range(1, 30))->map(fn ($i) => "Frase número $i del texto.")->implode(' ');
    $chunks = (new TextChunker(200, 60))->chunk($text);

    // La última palabra completa del fragmento n aparece al inicio del n+1.
    $lastWords = explode(' ', trim($chunks[0]));
    $tailWord = $lastWords[count($lastWords) - 2]; // evita la palabra final con punto
    expect($chunks[1])->toContain($tailWord);
});

test('una oración más larga que el tamaño se corta en trozos duros', function () {
    $chunks = (new TextChunker(100, 0))->chunk(str_repeat('a', 350));

    expect($chunks)->toHaveCount(4)
        ->and(mb_strlen($chunks[0]))->toBe(100);
});

test('conserva tildes y caracteres multibyte', function () {
    $chunks = (new TextChunker(50, 0))->chunk(str_repeat('ñandú árbol ', 20));

    foreach ($chunks as $chunk) {
        expect(mb_check_encoding($chunk, 'UTF-8'))->toBeTrue();
    }
});
