<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 16/09/2026
 * DESCRIPTION : User file upload folder under sap_reports
 */
class FilesController extends Controller
{
    private const MAX_BYTES = 20971520;

    private function folder(): string
    {
        $dir = base_path('user_files');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    private function allowedExtensions(): array
    {
        return ['xlsx', 'xls', 'csv', 'pdf', 'txt', 'zip', 'png', 'jpg', 'jpeg'];
    }

    private function safeName(string $name): string
    {
        $base = basename(str_replace(["\0", '\\', '/'], '', $name));
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base) ?: 'file';
        $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        if (!in_array($ext, $this->allowedExtensions(), true)) {
            return '';
        }
        return $base;
    }

    private function requireFilesAccess(): void
    {
        if (!function_exists('sap_reports_can_files') || !sap_reports_can_files()) {
            header('Location: ' . url());
            exit;
        }
    }

    public function index(): void
    {
        $this->requireFilesAccess();
        $message = trim((string) ($_GET['msg'] ?? ''));
        $error = trim((string) ($_GET['err'] ?? ''));
        $files = [];
        $dir = $this->folder();
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.htaccess' || $entry === 'index.html') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($path)) {
                continue;
            }
            $files[] = [
                'name' => $entry,
                'size' => filesize($path) ?: 0,
                'time' => filemtime($path) ?: time(),
            ];
        }
        usort($files, static fn($a, $b) => $b['time'] <=> $a['time']);

        $this->view('files/index', [
            'pageTitle' => 'SAP Reports Files',
            'files' => $files,
            'message' => $message,
            'error' => $error,
            'folderLabel' => '/var/www/html/sap_reports/user_files',
        ]);
    }

    public function upload(): void
    {
        $this->requireFilesAccess();
        if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file((string) $_FILES['file']['tmp_name'])) {
            $this->redirect('err=Choose a file to upload.');
        }
        if ((int) ($_FILES['file']['error'] ?? 0) !== UPLOAD_ERR_OK) {
            $this->redirect('err=Upload failed.');
        }
        if ((int) ($_FILES['file']['size'] ?? 0) > self::MAX_BYTES) {
            $this->redirect('err=File is larger than 20 MB.');
        }
        $name = $this->safeName((string) ($_FILES['file']['name'] ?? ''));
        if ($name === '') {
            $this->redirect('err=Allowed types: xlsx, xls, csv, pdf, txt, zip, png, jpg.');
        }
        $target = $this->folder() . DIRECTORY_SEPARATOR . $name;
        if (!move_uploaded_file((string) $_FILES['file']['tmp_name'], $target)) {
            $this->redirect('err=Could not save the file. Check folder permissions.');
        }
        $this->redirect('msg=' . rawurlencode($name . ' uploaded.'));
    }

    public function download(): void
    {
        $this->requireFilesAccess();
        $name = $this->safeName((string) ($_GET['name'] ?? ''));
        $path = $this->folder() . DIRECTORY_SEPARATOR . $name;
        if ($name === '' || !is_file($path)) {
            $this->notFound('File not found.');
            return;
        }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
        exit;
    }

    private function redirect(string $query): void
    {
        header('Location: ' . url('files') . '?' . $query);
        exit;
    }
}
