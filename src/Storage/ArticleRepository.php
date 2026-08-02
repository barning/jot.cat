<?php

declare(strict_types=1);

namespace Jot\Storage;

use PDO;
use Throwable;

final class ArticleRepository
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
    }

    public static function connect(string $path): self
    {
        return new self(new PDO('sqlite:' . $path));
    }

    public function findIdByUrl(string $url): ?string
    {
        $statement = $this->pdo->prepare('SELECT id FROM articles WHERE url = :url');
        $statement->execute(['url' => $url]);
        $value = $statement->fetchColumn();
        return is_string($value) ? $value : null;
    }

    public function findUrlById(string $id): ?string
    {
        $statement = $this->pdo->prepare('SELECT url FROM articles WHERE id = :id');
        $statement->execute(['id' => $id]);
        $value = $statement->fetchColumn();
        return is_string($value) ? $value : null;
    }

    public function insert(string $id, string $url): InsertResult
    {
        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare('INSERT OR IGNORE INTO articles (id, url) VALUES (:id, :url)');
            $statement->execute(['id' => $id, 'url' => $url]);

            if ($statement->rowCount() === 1) {
                $this->pdo->commit();
                return InsertResult::inserted();
            }

            $existingId = $this->findIdByUrl($url);
            $this->pdo->commit();

            return $existingId !== null
                ? InsertResult::duplicateUrl($existingId)
                : InsertResult::idCollision();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $error;
        }
    }
}
