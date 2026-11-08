<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;

/** Tenant-scoped external phonebook contacts and Excel-compatible CSV import. */
final class ContactController extends BaseController
{
    private const MAX_IMPORT_BYTES = 2_097_152;
    private const MAX_IMPORT_ROWS = 1000;

    public function index(Request $request): Response
    {
        $tid = $this->requireTenant();
        $q = $request->str('q');
        $page = $request->page();
        $per = $request->perPage();
        $where = 'tenant_id = ?';
        $params = [$tid];
        if ($q !== '') {
            $where .= " AND (name LIKE ? ESCAPE '!' OR phone LIKE ? ESCAPE '!' OR email LIKE ? ESCAPE '!' OR company LIKE ? ESCAPE '!')";
            $like = $this->likePattern($q);
            array_push($params, $like, $like, $like, $like);
        }
        $total = (int) $this->db()->scalar("SELECT COUNT(*) FROM contacts WHERE {$where}", $params);
        $pages = max(1, (int) ceil($total / $per));
        $page = min($page, $pages);
        $rows = $this->db()->all(
            "SELECT * FROM contacts WHERE {$where} ORDER BY name, phone LIMIT " . (int) $per . ' OFFSET ' . (int) (($page - 1) * $per),
            $params
        );
        return $this->view('contacts/index', compact('rows', 'q', 'page', 'pages', 'total'));
    }

    public function create(Request $request): Response
    {
        return $this->view('contacts/form', ['row' => null]);
    }

    public function store(Request $request): Response
    {
        $tid = $this->requireTenant();
        $data = $this->collect($request);
        if ($error = $this->validateContact($data, $tid)) {
            $this->stashOld($request);
            Flash::error($error);
            return Response::redirect('/contacts/create');
        }
        $data += ['tenant_id' => $tid, 'created_at' => date('Y-m-d H:i:s')];
        $id = $this->db()->insert('contacts', $data);
        $this->clearOld();
        $this->audit('contact.create', 'contact', $id);
        Flash::success('Contact added.');
        return Response::redirect('/contacts');
    }

    public function edit(Request $request): Response
    {
        $row = $this->findScoped((int) $request->params['id']);
        if (!$row) {
            Flash::error('Contact not found.');
            return Response::redirect('/contacts');
        }
        return $this->view('contacts/form', ['row' => $row]);
    }

    public function update(Request $request): Response
    {
        $row = $this->findScoped((int) $request->params['id']);
        if (!$row) return Response::redirect('/contacts');
        $data = $this->collect($request);
        if ($error = $this->validateContact($data, (int) $row['tenant_id'], (int) $row['id'])) {
            $this->stashOld($request);
            Flash::error($error);
            return Response::redirect('/contacts/' . (int) $row['id'] . '/edit');
        }
        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->db()->update('contacts', (int) $row['id'], $data);
        $this->clearOld();
        $this->audit('contact.update', 'contact', (int) $row['id']);
        Flash::success('Contact updated.');
        return Response::redirect('/contacts');
    }

    public function destroy(Request $request): Response
    {
        $row = $this->findScoped((int) $request->params['id']);
        if ($row) {
            $this->db()->delete('contacts', (int) $row['id']);
            $this->audit('contact.delete', 'contact', (int) $row['id']);
            Flash::success('Contact deleted.');
        }
        return Response::redirect('/contacts');
    }

    public function importForm(Request $request): Response
    {
        return $this->view('contacts/import');
    }

    public function template(Request $request): Response
    {
        $csv = "\xEF\xBB\xBFName,Phone,Email,Company,Notes\r\n";
        $csv .= 'Example Contact,+15551234567,contact@example.com,Example Ltd,Main office' . "\r\n";
        return (new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="contacts-import-template.csv"',
            'Cache-Control' => 'no-store, private',
        ]));
    }

    public function import(Request $request): Response
    {
        $tid = $this->requireTenant();
        $file = $request->files('contacts_file');
        $error = $this->uploadError($file);
        if ($error !== null) {
            Flash::error($error);
            return Response::redirect('/contacts/import');
        }

        $handle = fopen((string) $file['tmp_name'], 'rb');
        if (!$handle) {
            Flash::error('The contact file could not be read.');
            return Response::redirect('/contacts/import');
        }
        $header = fgetcsv($handle, 0, ',', '"', '');
        if (!is_array($header)) {
            fclose($handle);
            Flash::error('The CSV file is empty.');
            return Response::redirect('/contacts/import');
        }
        $header = array_map(static function ($value): string {
            $value = preg_replace('/^\xEF\xBB\xBF/', '', trim((string) $value)) ?? '';
            return strtolower(preg_replace('/[^a-z0-9]/i', '', $value) ?? '');
        }, $header);
        $indexes = array_flip($header);
        if (!isset($indexes['name'], $indexes['phone'])) {
            fclose($handle);
            Flash::error('The CSV header must include Name and Phone columns. Download the template and keep its header row.');
            return Response::redirect('/contacts/import');
        }

        $existing = [];
        foreach ($this->db()->all('SELECT phone FROM contacts WHERE tenant_id = ?', [$tid]) as $row) {
            $existing[(string) $row['phone']] = true;
        }
        $inserted = 0;
        $skipped = 0;
        $invalid = 0;
        $line = 1;
        $pdo = $this->db()->pdo();
        $pdo->beginTransaction();
        try {
            while (($values = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $line++;
                if ($line > self::MAX_IMPORT_ROWS + 1) throw new \RuntimeException('The import is limited to ' . self::MAX_IMPORT_ROWS . ' contact rows.');
                if (count(array_filter($values, static fn ($value): bool => trim((string) $value) !== '')) === 0) continue;
                $pick = static fn (string $key): string => isset($indexes[$key]) ? trim((string) ($values[$indexes[$key]] ?? '')) : '';
                $data = [
                    'name' => $pick('name'),
                    'phone' => $this->normalizePhone($pick('phone')),
                    'email' => $pick('email'),
                    'company' => $pick('company'),
                    'notes' => $pick('notes'),
                ];
                if ($this->contactDataError($data) !== null) { $invalid++; continue; }
                if (isset($existing[$data['phone']])) { $skipped++; continue; }
                $data += ['tenant_id' => $tid, 'created_at' => date('Y-m-d H:i:s')];
                $this->db()->insert('contacts', $data);
                $existing[$data['phone']] = true;
                $inserted++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            fclose($handle);
            Flash::error($e->getMessage());
            return Response::redirect('/contacts/import');
        }
        fclose($handle);
        $this->audit('contact.import', 'contact', null, compact('inserted', 'skipped', 'invalid'));
        Flash::success("Imported {$inserted} contact(s). Skipped {$skipped} duplicate(s) and {$invalid} invalid row(s).");
        return Response::redirect('/contacts');
    }

    private function collect(Request $request): array
    {
        return [
            'name' => mb_substr($request->str('name'), 0, 120),
            'phone' => $this->normalizePhone($request->str('phone')),
            'email' => mb_substr($request->str('email'), 0, 160),
            'company' => mb_substr($request->str('company'), 0, 120),
            'notes' => mb_substr($request->str('notes'), 0, 2000),
        ];
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/[\s().-]+/', '', trim($phone)) ?? '';
    }

    private function contactDataError(array $data): ?string
    {
        if ($data['name'] === '' || mb_strlen($data['name']) > 120) return 'Every contact needs a name of 120 characters or fewer.';
        if (!preg_match('/^\+?[0-9*#]{2,40}$/', (string) $data['phone'])) return 'Enter a valid phone number using digits and an optional leading +.';
        if ($data['email'] !== '' && (!filter_var($data['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($data['email']) > 160)) return 'Enter a valid email address.';
        if (mb_strlen((string) $data['company']) > 120 || mb_strlen((string) $data['notes']) > 2000) return 'Company or notes are too long.';
        return null;
    }

    private function validateContact(array $data, int $tenantId, ?int $exceptId = null): ?string
    {
        if ($error = $this->contactDataError($data)) return $error;
        $sql = 'SELECT COUNT(*) FROM contacts WHERE tenant_id = ? AND phone = ?';
        $params = [$tenantId, $data['phone']];
        if ($exceptId !== null) { $sql .= ' AND id <> ?'; $params[] = $exceptId; }
        return (int) $this->db()->scalar($sql, $params) > 0 ? 'A contact with that phone number already exists.' : null;
    }

    private function findScoped(int $id): ?array
    {
        $tid = $this->tenantId();
        return $tid ? $this->db()->one('SELECT * FROM contacts WHERE id = ? AND tenant_id = ?', [$id, $tid]) : null;
    }

    private function uploadError(?array $file): ?string
    {
        if (!$file || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return 'Choose a CSV contact file.';
        if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) return 'The contact file upload failed.';
        if ((int) ($file['size'] ?? 0) < 1 || (int) $file['size'] > self::MAX_IMPORT_BYTES) return 'The CSV file must be no larger than 2 MB.';
        if (strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'csv') return 'Upload a .csv file created from the contact template.';
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
        $allowed = ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel', 'application/octet-stream'];
        return in_array($mime, $allowed, true) ? null : 'The uploaded file is not a valid CSV document.';
    }
}
