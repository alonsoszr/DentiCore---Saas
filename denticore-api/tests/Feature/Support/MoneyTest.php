<?php

/*
 * Aritmética monetaria exacta (TASK-012; SDD DI-05, §4.1; RNF-001, RNF-046).
 */

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Tests\Support\SeededAmounts;

/** Modelo de prueba sobre una tabla temporal numeric(12,2). */
class MoneyProbe extends Model
{
    protected $table = 'money_probe';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => MoneyCast::class];
    }
}

it('adds 0.10 and 0.20 to exactly 0.30', function () {
    $total = Money::of('0.10')->plus('0.20');

    expect((string) $total)->toBe('0.30')
        ->and($total->equals(Money::of('0.3')))->toBeTrue();
})->group('RNF-001', 'DI-05');

it('rounds half up to two decimals', function () {
    expect((string) Money::of('2.345'))->toBe('2.35')
        ->and((string) Money::of('2.344'))->toBe('2.34')
        ->and((string) Money::of('-2.345'))->toBe('-2.35')
        ->and((string) Money::of('10.00')->multipliedBy('0.18'))->toBe('1.80')
        ->and((string) Money::of('294.00')->dividedBy('1.18'))->toBe('249.15');
})->group('RNF-001', 'DI-05');

it('rejects floats and non numeric text', function () {
    expect(fn () => Money::of(0.1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::of('1')->plus(0.2))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::of('1')->multipliedBy(0.18))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::of('abc'))->toThrow(InvalidArgumentException::class);
})->group('RNF-001');

it('compares amounts', function () {
    $ten = Money::of('10');

    expect($ten->isGreaterThan(Money::of('9.99')))->toBeTrue()
        ->and($ten->isLessThan(Money::of('10.01')))->toBeTrue()
        ->and(Money::zero()->isZero())->toBeTrue()
        ->and($ten->minus('10.01')->isNegative())->toBeTrue()
        ->and(Money::of($ten))->toBe($ten)
        ->and((string) $ten->toBigDecimal())->toBe('10.00');
});

it('keeps two decimals in a round trip through MoneyCast', function () {
    DB::statement('create temporary table money_probe (id bigserial primary key, amount numeric(12,2))');

    $probe = MoneyProbe::query()->create(['amount' => '1234.5']);
    $stored = DB::selectOne('select amount::text as amount from money_probe where id = ?', [$probe->id])->amount;
    $fresh = MoneyProbe::query()->find($probe->id);

    expect($stored)->toBe('1234.50')
        ->and($fresh->amount)->toBeInstanceOf(Money::class)
        ->and((string) $fresh->amount)->toBe('1234.50');

    $fresh->update(['amount' => null]);
    expect($fresh->fresh()->amount)->toBeNull();
})->group('RNF-001', 'DI-05');

it('serializes amounts in JSON as strings', function () {
    expect(json_encode(['total' => Money::of('294')]))->toBe('{"total":"294.00"}');
})->group('RNF-046');

it('matches exact cent arithmetic in 10000 seeded random sums', function () {
    $amounts = new SeededAmounts;

    for ($case = 0; $case < 10_000; $case++) {
        $a = $amounts->cents(-99_999_999, 99_999_999);
        $b = $amounts->cents(-99_999_999, 99_999_999);

        $sum = Money::of(SeededAmounts::format($a))->plus(SeededAmounts::format($b));
        $difference = Money::of(SeededAmounts::format($a))->minus(SeededAmounts::format($b));

        expect((string) $sum)->toBe(SeededAmounts::format($a + $b), "semilla {$amounts->seed}, caso {$case}")
            ->and((string) $difference)->toBe(SeededAmounts::format($a - $b), "semilla {$amounts->seed}, caso {$case}");
    }
})->group('RNF-001');
