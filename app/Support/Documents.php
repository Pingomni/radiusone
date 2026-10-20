<?php

declare(strict_types=1);

namespace App\Support;

final class Documents
{
    public static function directory(): string
    {
        return rtrim((string) App::env('DOCUMENT_STORAGE', App::root('storage/documents')), '/');
    }
    public static function validateUploads(): array
    {
        if (!Auth::can('documents.manage')) {
            return [];
        }
        $uploads = [];
        $max = max(1, (int) App::env('DOCUMENT_MAX_UPLOAD_MB', 8)) * 1024 * 1024;
        foreach (['id_front', 'id_back'] as $field) {
            $upload = Security::uploadInfo($field, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], $max);
            if ($upload) {
                $uploads[$field] = $upload;
            }
        }
        return $uploads;
    }
    /** A separate atomic upload outcome; provisioning has already committed. */
    public static function store(string $username, array $uploads, int $operator, ?\Closure $move = null): void
    {
        if (!$uploads) {
            return;
        }
        $base = self::directory();
        if (!is_dir($base) || !is_writable($base)) {
            throw new \RuntimeException('Document storage is not configured writable.');
        }
        $move ??= static fn ($source, $target) => move_uploaded_file($source, $target);
        $pdo = Db::pdo();
        $files = [];
        $pdo->beginTransaction();
        try {
            foreach ($uploads as $field => $upload) {
                $stored = Security::safeFilenameStem($username, 'client') . '-' . $field . '-' . bin2hex(random_bytes(16)) . '.' . $upload['extension'];
                $target = $base . '/' . $stored;
                if (!$move($upload['tmp_name'], $target)) {
                    throw new \RuntimeException('Could not save document.');
                }
                $files[] = $target;
                if (!chmod($target, 0640)) {
                    throw new \RuntimeException('Could not protect document.');
                }
                Db::exec('INSERT INTO uploaded_documents(username,document_type,original_name,stored_path,mime_type,uploaded_by,created_at) VALUES(?,?,?,?,?,?,NOW())', [$username, $field, $upload['name'], $stored, $upload['mime'], $operator]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            foreach ($files as $file) {
                if (is_file($file) && !unlink($file)) {
                    Diagnostics::report(new \RuntimeException('Document compensation failed'));
                }
            }
            throw $e;
        }
    }
    public static function path(array $document): string
    {
        $name = (string) $document['stored_path'];
        if ($name !== basename($name) || !preg_match('/^[A-Za-z0-9._-]+$/D', $name)) {
            throw new \RuntimeException('Invalid document path.');
        }
        $base = realpath(self::directory());
        $path = $base === false ? false : realpath($base . '/' . $name);
        if ($path === false || !str_starts_with($path, $base . '/') || is_link($base . '/' . $name) || !is_file($path)) {
            throw new \RuntimeException('Document file missing or unsafe.');
        }
        return $path;
    }
    public static function download(int $id): array
    {
        if (!Auth::can('documents.view')) {
            throw new \RuntimeException('Document access denied.');
        }
        $doc = Db::row('SELECT * FROM uploaded_documents WHERE id=?', [$id]);
        if (!$doc) {
            throw new \RuntimeException('Document not found.');
        }
        $path = self::path($doc);
        Audit::log('download', 'document', (string) $id, null, ['username' => $doc['username']]);
        return [$doc, $path];
    }
    /** Read-only inventory; crash remnants are never removed automatically. */
    public static function inventory(): array
    {
        $referenced = Db::select('SELECT id,stored_path FROM uploaded_documents');
        $known = [];
        $missing = [];
        foreach ($referenced as $row) {
            $known[$row['stored_path']] = true;
            try {
                self::path($row);
            } catch (\RuntimeException) {
                $missing[] = $row['id'];
            }
        }
        $orphans = [];
        foreach (glob(self::directory() . '/*') ?: [] as $file) {
            if (!isset($known[basename($file)])) {
                $orphans[] = basename($file);
            }
        }
        return ['missing_document_ids' => $missing, 'unreferenced_files' => $orphans];
    }
}
