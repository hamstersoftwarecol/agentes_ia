<?php

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Organization;
use App\Support\CurrentOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Modelo mínimo de prueba: representa cualquier tabla de negocio con organization_id. */
class TenantNote extends Model
{
    use BelongsToOrganization;

    protected $table = 'tenant_notes';

    protected $guarded = [];
}

beforeEach(function () {
    Schema::create('tenant_notes', function (Blueprint $table) {
        $table->id();
        $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
        $table->string('body');
        $table->timestamps();
    });

    $this->a = Organization::factory()->create();
    $this->b = Organization::factory()->create();
    TenantNote::withoutGlobalScopes()->create(['organization_id' => $this->a->id, 'body' => 'nota de A']);
    TenantNote::withoutGlobalScopes()->create(['organization_id' => $this->b->id, 'body' => 'nota de B']);
});

afterEach(fn () => Schema::dropIfExists('tenant_notes'));

test('cada empresa solo ve sus propios registros', function () {
    app(CurrentOrganization::class)->set($this->a);
    expect(TenantNote::pluck('body')->all())->toBe(['nota de A']);

    app(CurrentOrganization::class)->set($this->b);
    expect(TenantNote::pluck('body')->all())->toBe(['nota de B']);
});

test('sin empresa actual no se ve nada (falla cerrado)', function () {
    app(CurrentOrganization::class)->set(null);

    expect(TenantNote::count())->toBe(0);
});

test('un registro de otra empresa no se puede encontrar por id', function () {
    $foreign = TenantNote::withoutGlobalScopes()->where('organization_id', $this->b->id)->first();
    app(CurrentOrganization::class)->set($this->a);

    expect(TenantNote::find($foreign->id))->toBeNull();
});

test('al crear se asigna la empresa actual automáticamente', function () {
    app(CurrentOrganization::class)->set($this->a);

    $note = TenantNote::create(['body' => 'nueva']);

    expect($note->organization_id)->toBe($this->a->id);
});

test('crear sin empresa actual lanza excepción', function () {
    app(CurrentOrganization::class)->set(null);

    TenantNote::create(['body' => 'huérfana']);
})->throws(LogicException::class);
