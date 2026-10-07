<?php

use App\Enums\OrganizationRole;

test('quién puede asignar o quitar qué rol', function (OrganizationRole $actor, OrganizationRole $target, bool $allowed) {
    expect($actor->canAssign($target))->toBe($allowed);
})->with([
    'propietario → administrador' => [OrganizationRole::Owner, OrganizationRole::Admin, true],
    'propietario → agente' => [OrganizationRole::Owner, OrganizationRole::Agent, true],
    'propietario → propietario' => [OrganizationRole::Owner, OrganizationRole::Owner, false],
    'administrador → agente' => [OrganizationRole::Admin, OrganizationRole::Agent, true],
    'administrador → administrador' => [OrganizationRole::Admin, OrganizationRole::Admin, false],
    'administrador → propietario' => [OrganizationRole::Admin, OrganizationRole::Owner, false],
    'agente → agente' => [OrganizationRole::Agent, OrganizationRole::Agent, false],
    'agente → administrador' => [OrganizationRole::Agent, OrganizationRole::Admin, false],
]);

test('cada rol tiene su nombre en español', function () {
    expect(array_map(fn (OrganizationRole $r) => $r->label(), OrganizationRole::cases()))->toBe(['propietario', 'administrador', 'agente']);
});
