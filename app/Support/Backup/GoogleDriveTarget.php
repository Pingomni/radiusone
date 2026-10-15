<?php

declare(strict_types=1);

namespace App\Support\Backup;

use App\Support\ProviderHttp;
use RuntimeException;

/**
 * Google Drive through the Drive v3 REST API with the drive.file scope: the app only sees the folder
 * and files it created, nothing else in the Drive. Connected once from Settings → Backups (OAuth with
 * the operator's own Google Cloud OAuth client); the refresh token is stored encrypted.
 */
final class GoogleDriveTarget implements BackupTarget
{
    public const SCOPE = 'https://www.googleapis.com/auth/drive.file';
    private const HOSTS = ['www.googleapis.com', 'oauth2.googleapis.com'];

    private ?string $token = null;

    public function __construct(private readonly array $c)
    {
    }

    public static function validate(array $c): void
    {
        if (!preg_match('/^[0-9]+-[a-z0-9]+\.apps\.googleusercontent\.com$/D', $c['client_id'] ?? '') || ($c['client_secret'] ?? '') === ''
            || !preg_match('/^[^\/\\\\]{1,100}$/D', $c['folder'] ?? '')) {
            throw new \InvalidArgumentException('Enter the OAuth client ID (…apps.googleusercontent.com), the client secret and a folder name.');
        }
    }

    public static function authorizeUrl(array $c, string $redirect, string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $c['client_id'], 'redirect_uri' => $redirect, 'response_type' => 'code', 'scope' => self::SCOPE,
            'access_type' => 'offline', 'prompt' => 'consent', 'state' => $state,
        ]);
    }

    /**
     * Exchanges the code from Google's redirect for a refresh token and creates the backup folder.
     * @return array{refresh_token: string, folder_id: string}
     */
    public static function connect(array $c, string $code, string $redirect): array
    {
        [$status, $body] = ProviderHttp::request('POST', 'https://oauth2.googleapis.com/token', self::HOSTS, ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query(['code' => $code, 'client_id' => $c['client_id'], 'client_secret' => $c['client_secret'], 'redirect_uri' => $redirect, 'grant_type' => 'authorization_code']));
        $refresh = (string) (ProviderHttp::json($body)['refresh_token'] ?? '');
        if ($status !== 200 || $refresh === '') {
            throw new RuntimeException('Google did not grant access: ' . self::error($body));
        }
        $target = new self(array_replace($c, ['refresh_token' => $refresh]));
        [$status, $body] = $target->api('POST', 'https://www.googleapis.com/drive/v3/files?fields=id', ['Content-Type' => 'application/json'],
            json_encode(['name' => $c['folder'], 'mimeType' => 'application/vnd.google-apps.folder'], JSON_THROW_ON_ERROR));
        $folder = (string) (ProviderHttp::json($body)['id'] ?? '');
        if ($status !== 200 || $folder === '') {
            throw new RuntimeException('Could not create the Drive folder: ' . self::error($body));
        }
        return ['refresh_token' => $refresh, 'folder_id' => $folder];
    }

    public function put(string $file, string $name): void
    {
        [$status, $body, $headers] = $this->api('POST', 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&fields=id',
            ['Content-Type' => 'application/json; charset=UTF-8', 'X-Upload-Content-Type' => 'application/octet-stream', 'X-Upload-Content-Length' => (string) filesize($file)],
            json_encode(['name' => $name, 'parents' => [$this->folder()]], JSON_THROW_ON_ERROR));
        $session = (string) ($headers['location'] ?? '');
        if ($status !== 200 || !str_starts_with($session, 'https://www.googleapis.com/upload/drive/v3/files?')) {
            throw new RuntimeException('Google Drive did not start the upload (HTTP ' . $status . '): ' . self::error($body));
        }
        [$status, $body] = ProviderHttp::request('PUT', $session, self::HOSTS, ['Authorization' => 'Bearer ' . $this->accessToken(), 'Content-Type' => 'application/octet-stream'], ['file' => $file], 900);
        if (!in_array($status, [200, 201], true)) {
            throw new RuntimeException('Google Drive upload failed (HTTP ' . $status . '): ' . self::error($body));
        }
    }

    public function list(): array
    {
        $files = [];
        $page = null;
        do {
            $query = ['q' => "'" . $this->folder() . "' in parents and trashed = false and name contains 'frweb-'", 'fields' => 'nextPageToken,files(id,name)', 'pageSize' => '1000']
                + ($page !== null ? ['pageToken' => $page] : []);
            [$status, $body] = $this->api('GET', 'https://www.googleapis.com/drive/v3/files?' . http_build_query($query));
            if ($status !== 200) {
                throw new RuntimeException('Google Drive listing failed (HTTP ' . $status . '): ' . self::error($body));
            }
            $data = ProviderHttp::json($body);
            foreach ($data['files'] ?? [] as $file) {
                if (str_starts_with((string) $file['name'], 'frweb-')) {
                    $files[(string) $file['name']] = (string) $file['id'];
                }
            }
            $page = $data['nextPageToken'] ?? null;
        } while ($page !== null);
        return $files;
    }

    public function delete(string $id): void
    {
        [$status, $body] = $this->api('DELETE', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($id));
        if ($status !== 204 && $status !== 404) {
            throw new RuntimeException('Google Drive delete failed (HTTP ' . $status . '): ' . self::error($body));
        }
    }

    private function folder(): string
    {
        $folder = (string) ($this->c['folder_id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]+$/D', $folder)) {
            throw new RuntimeException('Google Drive is not connected yet.');
        }
        return $folder;
    }

    private function accessToken(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }
        if (($this->c['refresh_token'] ?? '') === '') {
            throw new RuntimeException('Google Drive is not connected yet.');
        }
        [$status, $body] = ProviderHttp::request('POST', 'https://oauth2.googleapis.com/token', self::HOSTS, ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query(['client_id' => $this->c['client_id'], 'client_secret' => $this->c['client_secret'], 'refresh_token' => $this->c['refresh_token'], 'grant_type' => 'refresh_token']));
        $token = (string) (ProviderHttp::json($body)['access_token'] ?? '');
        if ($status !== 200 || $token === '') {
            throw new RuntimeException('Google Drive access was revoked or expired; connect it again (' . self::error($body) . ').');
        }
        return $this->token = $token;
    }

    /** @return array{0: int, 1: string, 2: array<string, string>} */
    private function api(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        return ProviderHttp::request($method, $url, self::HOSTS, ['Authorization' => 'Bearer ' . $this->accessToken()] + $headers, $body, 60);
    }

    private static function error(string $body): string
    {
        $data = ProviderHttp::json($body);
        $text = $data['error']['message'] ?? $data['error_description'] ?? (is_string($data['error'] ?? null) ? $data['error'] : null) ?? trim($body);
        return mb_substr((string) $text ?: 'no details', 0, 200);
    }
}
