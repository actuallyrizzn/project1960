<?php
declare(strict_types=1);

namespace Project1960;

use PDO;

final class CaseRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{
     *   cases: list<array<string, mixed>>,
     *   total: int,
     *   page: int,
     *   per_page: int,
     *   total_pages: int,
     *   filters: CaseListFilters
     * }
     */
    public function list(CaseListFilters $filters): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $countSql = 'SELECT COUNT(*) FROM cases WHERE ' . $where;
        $stmt = $this->pdo->prepare($countSql);
        $this->bindAll($stmt, $params);
        $stmt->execute();
        $total = (int) $stmt->fetchColumn();

        $sql = 'SELECT * FROM cases WHERE ' . $where
            . ' ORDER BY date DESC LIMIT ? OFFSET ?';
        $stmt = $this->pdo->prepare($sql);
        $this->bindAll($stmt, $params);
        $limitPos = count($params) + 1;
        $stmt->bindValue($limitPos, $filters->perPage, PDO::PARAM_INT);
        $stmt->bindValue($limitPos + 1, $filters->offset(), PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string, mixed>> $cases */
        $cases = $stmt->fetchAll();

        $totalPages = $total === 0 ? 1 : (int) ceil($total / $filters->perPage);

        return [
            'cases' => $cases,
            'total' => $total,
            'page' => $filters->page,
            'per_page' => $filters->perPage,
            'total_pages' => $totalPages,
            'filters' => $filters,
        ];
    }

    /**
     * @param list<string|int> $params
     */
    private function bindAll(\PDOStatement $stmt, array $params): void
    {
        foreach ($params as $i => $value) {
            $pos = $i + 1;
            if (is_int($value)) {
                $stmt->bindValue($pos, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($pos, $value, PDO::PARAM_STR);
            }
        }
    }

    /**
     * @return array{0: string, 1: list<string|int>}
     */
    private function buildWhere(CaseListFilters $filters): array
    {
        $clauses = ['1=1'];
        $params = [];

        if ($filters->classification !== '') {
            $clauses[] = 'classification = ?';
            $params[] = $filters->classification;
        }

        if ($filters->mentions1960 === '0' || $filters->mentions1960 === '1') {
            $clauses[] = 'mentions_1960 = ?';
            $params[] = (int) $filters->mentions1960;
        }

        if ($filters->mentionsCrypto === '0' || $filters->mentionsCrypto === '1') {
            $clauses[] = 'mentions_crypto = ?';
            $params[] = (int) $filters->mentionsCrypto;
        }

        if ($filters->verified1960 === '0' || $filters->verified1960 === '1') {
            $clauses[] = 'verified_1960 = ?';
            $params[] = (int) $filters->verified1960;
        }

        if ($filters->search !== '') {
            $clauses[] = '(title LIKE ? OR body LIKE ?)';
            $term = '%' . $filters->search . '%';
            $params[] = $term;
            $params[] = $term;
        }

        return [implode(' AND ', $clauses), $params];
    }
}
