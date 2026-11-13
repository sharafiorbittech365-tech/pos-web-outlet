<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Services;

use CodeIgniter\Database\BaseBuilder;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Models\Generated\ExpensesModel;

/**
 * Expense book of the current outlet. A cash expense is linked to the open register so it comes
 * out of the drawer's expected cash.
 */
final class ExpenseService
{
    public const METHODS = ['cash' => 'Cash drawer', 'bank' => 'Bank', 'mobile' => 'bKash / Nagad / Rocket', 'cheque' => 'Cheque', 'card' => 'Card'];

    public function __construct(private readonly array $business)
    {
    }

    public static function make(): self
    {
        return new self(service('posSession')->business());
    }

    private function bid(): int
    {
        return service('tenant')->businessId();
    }

    /** @return list<string> */
    public function categories(): array
    {
        $cats = array_values(array_filter(array_map('strval', (array) ($this->business['settings']['expenseCats'] ?? []))));

        return $cats !== [] ? $cats : ['Rent', 'Salary', 'Utilities', 'Transport', 'Other'];
    }

    public function listQuery(string $from, string $to, string $category, string $method): BaseBuilder
    {
        $b = db_connect()->table('expenses e')
            ->select('e.id, e.category, e.amount, e.method, e.note, e.spent_at, e.register_id, u.name AS user_name')
            ->join('users u', 'u.id = e.user_id', 'left')
            ->where('e.business_id', $this->bid())->where('e.spent_at >=', $from)->where('e.spent_at <', $to);
        if ($category !== '') {
            $b->where('e.category', $category);
        }
        if ($method !== '') {
            $b->where('e.method', $method);
        }

        return $b;
    }

    /** @return array{total: float, cash: float, other: float, n: int, byCat: list<array{category: string, amount: float, n: int}>} */
    public function summary(string $from, string $to): array
    {
        $rows = db_connect()->table('expenses')->select('category, method, SUM(amount) AS amount, COUNT(*) AS n')
            ->where('business_id', $this->bid())->where('spent_at >=', $from)->where('spent_at <', $to)
            ->groupBy(['category', 'method'])->get()->getResultArray();
        $total = $cash = 0.0;
        $n = 0;
        $byCat = [];
        foreach ($rows as $r) {
            $a = (float) $r['amount'];
            $total += $a;
            $n += (int) $r['n'];
            if ($r['method'] === 'cash') {
                $cash += $a;
            }
            $byCat[$r['category']] ??= ['category' => $r['category'], 'amount' => 0.0, 'n' => 0];
            $byCat[$r['category']]['amount'] += $a;
            $byCat[$r['category']]['n'] += (int) $r['n'];
        }
        usort($byCat, static fn (array $x, array $y): int => $y['amount'] <=> $x['amount']);

        return ['total' => $total, 'cash' => $cash, 'other' => $total - $cash, 'n' => $n, 'byCat' => array_values($byCat)];
    }

    /** @param array<string, mixed> $in category, amount, method, note, spent_at (Y-m-d or Y-m-d\TH:i) */
    public function create(array $in): int
    {
        $errors = [];
        $category = mb_substr(trim((string) ($in['category'] ?? '')), 0, 60);
        if ($category === '') {
            $errors['category'] = 'Choose a category.';
        }
        $amount = round((float) ($in['amount'] ?? 0), 2);
        if ($amount <= 0) {
            $errors['amount'] = 'Enter an amount above zero.';
        }
        $method = array_key_exists((string) ($in['method'] ?? ''), self::METHODS) ? (string) $in['method'] : 'cash';
        $when = trim((string) ($in['spent_at'] ?? ''));
        $t = $when !== '' ? strtotime($when) : time();
        if ($t === false || $t > time() + 300) {
            $errors['spent_at'] = 'The date cannot be in the future.';
        }
        if ($errors !== []) {
            throw new ValidationException('Please check the expense.', $errors);
        }
        // a date without a time on another day is noon of that day
        $spent = strlen($when) === 10 && $when !== date('Y-m-d') ? date('Y-m-d 12:00:00', (int) $t) : date('Y-m-d H:i:s', (int) $t);
        $register = null;
        if ($method === 'cash' && substr($spent, 0, 10) === date('Y-m-d')) {
            $register = db_connect()->table('registers')->select('id')->where('business_id', $this->bid())->where('closed_at', null)->orderBy('opened_at', 'DESC')->limit(1)->get()->getRow('id');
        }
        $id = (int) (new ExpensesModel())->insert([
            'category' => $category, 'amount' => $amount, 'method' => $method, 'note' => mb_substr(trim((string) ($in['note'] ?? '')), 0, 255) ?: null,
            'register_id' => $register !== null ? (int) $register : null, 'user_id' => service('tenant')->userId(), 'spent_at' => $spent,
        ]);
        service('audit')->activity('Expense', $category . ' · ' . number_format($amount, 2) . ' · ' . $method);

        return $id;
    }

    public function delete(int $id): void
    {
        $m = new ExpensesModel();
        $e = $m->find($id) ?? throw new NotFoundException('Expense not found.');
        $m->delete($id);
        service('audit')->activity('Expense deleted', $e['category'] . ' · ' . number_format((float) $e['amount'], 2));
    }
}
