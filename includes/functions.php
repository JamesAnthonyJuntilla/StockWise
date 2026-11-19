<?php
/**
 * StockWise — Utility / Helper Functions
 */

// ─── Formatting ────────────────────────────────────────────────────────────────

/**
 * Format a numeric amount as USD currency, e.g. "$1,234.56".
 */
function formatCurrency(float $amount): string
{
    return '$' . number_format($amount, 2);
}

/**
 * Format a date/datetime string as "Nov 19, 2026".
 */
function formatDate(string $datetime): string
{
    return date('M j, Y', strtotime($datetime));
}

/**
 * Format a date/datetime string as "Nov 19, 2026 7:32 PM".
 */
function formatDateTime(string $datetime): string
{
    return date('M j, Y g:i A', strtotime($datetime));
}

// ─── SKU & Receipt Generation ──────────────────────────────────────────────────

/**
 * Generate a unique product SKU like "ELEC-00042".
 *
 * Finds the highest existing numeric suffix for the given prefix
 * and returns the next one.  Pads to 5 digits.
 *
 * @param string $categoryCode Short uppercase code, e.g. "ELEC"
 * @param PDO    $pdo
 */
function generateSKU(string $categoryCode, PDO $pdo): string
{
    $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $categoryCode), 0, 6));

    $stmt = $pdo->prepare(
        "SELECT MAX(CAST(SUBSTRING(sku, LOCATE('-', sku) + 1) AS UNSIGNED))
         FROM products
         WHERE sku LIKE :prefix"
    );
    $stmt->execute([':prefix' => $prefix . '-%']);
    $max = (int) $stmt->fetchColumn();

    return $prefix . '-' . str_pad($max + 1, 5, '0', STR_PAD_LEFT);
}

/**
 * Generate a unique receipt number like "RCP-20261119-00001".
 *
 * Uses today's date as the middle segment and a padded daily counter stored
 * in the session to handle concurrent requests gracefully.  The database
 * UNIQUE constraint on receipt_no is the final guard.
 */
function generateReceiptNo(): string
{
    $datePart = date('Ymd');
    $key = 'receipt_counter_' . $datePart;

    if (empty($_SESSION[$key])) {
        $_SESSION[$key] = 1;
    } else {
        $_SESSION[$key]++;
    }

    return 'RCP-' . $datePart . '-' . str_pad($_SESSION[$key], 5, '0', STR_PAD_LEFT);
}

// ─── Input / Output Sanitization ───────────────────────────────────────────────

/**
 * Trim whitespace and convert special HTML characters to entities.
 *
 * @param string $input
 * @return string
 */
function sanitize(string $input): string
{
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// ─── Flash Messages ────────────────────────────────────────────────────────────

/**
 * Store a flash message in the session.
 *
 * @param string $type    Bootstrap color: success|danger|warning|info
 * @param string $message
 */
function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'msg' => $message];
}

/**
 * Return the current flash message array and clear it from the session.
 *
 * @return array{type:string,msg:string}|null
 */
function getFlash(): ?array
{
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Echo a Bootstrap dismissible alert if a flash message is queued.
 */
function renderFlash(): void
{
    $flash = getFlash();
    if (!$flash) {
        return;
    }
    $type = htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8');
    $msg  = htmlspecialchars($flash['msg'],  ENT_QUOTES, 'UTF-8');
    echo <<<HTML
<div class="alert alert-{$type} alert-dismissible fade show mx-3 mt-3" role="alert">
    <i class="bi bi-info-circle-fill me-2"></i>{$msg}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
HTML;
}

// ─── Pagination ────────────────────────────────────────────────────────────────

/**
 * Compute pagination metadata.
 *
 * @param int $totalRecords Total number of matching rows
 * @param int $perPage      Rows per page
 * @param int $currentPage  1-based current page
 *
 * @return array{
 *   total:         int,
 *   per_page:      int,
 *   current_page:  int,
 *   last_page:     int,
 *   offset:        int,
 *   has_prev:      bool,
 *   has_next:      bool,
 *   from:          int,
 *   to:            int,
 * }
 */
function paginate(int $totalRecords, int $perPage, int $currentPage): array
{
    $perPage     = max(1, $perPage);
    $lastPage    = max(1, (int) ceil($totalRecords / $perPage));
    $currentPage = max(1, min($currentPage, $lastPage));
    $offset      = ($currentPage - 1) * $perPage;
    $from        = $totalRecords ? $offset + 1 : 0;
    $to          = min($offset + $perPage, $totalRecords);

    return [
        'total'        => $totalRecords,
        'per_page'     => $perPage,
        'current_page' => $currentPage,
        'last_page'    => $lastPage,
        'offset'       => $offset,
        'has_prev'     => $currentPage > 1,
        'has_next'     => $currentPage < $lastPage,
        'from'         => $from,
        'to'           => $to,
    ];
}

// ─── Status Badge ──────────────────────────────────────────────────────────────

/**
 * Return a Bootstrap badge <span> for a given status string.
 *
 * @param string $status  active|inactive|pending|low_stock|out_of_stock
 * @return string HTML
 */
function getStatusBadge(string $status): string
{
    $map = [
        'active'       => ['success', 'Active'],
        'inactive'     => ['secondary', 'Inactive'],
        'pending'      => ['warning', 'Pending'],
        'low_stock'    => ['warning', 'Low Stock'],
        'out_of_stock' => ['danger',  'Out of Stock'],
        'completed'    => ['success', 'Completed'],
        'cancelled'    => ['danger',  'Cancelled'],
        'refunded'     => ['info',    'Refunded'],
    ];

    [$color, $label] = $map[strtolower($status)] ?? ['secondary', ucfirst($status)];
    $color = htmlspecialchars($color, ENT_QUOTES, 'UTF-8');
    $label = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    return "<span class=\"badge bg-{$color}\">{$label}</span>";
}

// ─── File Helpers ──────────────────────────────────────────────────────────────

/**
 * Convert bytes to a human-readable string.
 */
function formatFileSize(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

/**
 * Validate an uploaded image file.
 *
 * Checks MIME type (jpg, png, gif, webp) and maximum size (2 MB).
 *
 * @param array $file  Element from $_FILES
 * @return bool
 */
function isValidImageFile(array $file): bool
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }

    $maxSize = 2 * 1024 * 1024; // 2 MB
    if ($file['size'] > $maxSize) {
        return false;
    }

    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);

    return in_array($mime, $allowedMimes, true);
}

/**
 * Move an uploaded product image to `assets/images/products/` and return the filename.
 *
 * Returns false on failure.
 *
 * @param array $file       Element from $_FILES
 * @param int   $productId  Used to construct a unique filename
 * @return string|false
 */
function uploadProductImage(array $file, int $productId)
{
    $uploadDir = APP_ROOT . '/assets/images/products/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $finfo     = new finfo(FILEINFO_MIME_TYPE);
    $mime      = $finfo->file($file['tmp_name']);
    $extMap    = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];
    $ext = $extMap[$mime] ?? 'jpg';

    $filename = 'product_' . $productId . '_' . time() . '.' . $ext;
    $destPath = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        return false;
    }

    return $filename;
}

// ─── CSRF Protection ───────────────────────────────────────────────────────────

/**
 * Generate (or return the existing) CSRF token stored in the session.
 */
function getCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate a submitted CSRF token against the session token.
 *
 * Uses a timing-safe comparison to prevent timing attacks.
 *
 * @param string $token The token submitted in the request
 * @return bool
 */
function validateCsrf(string $token): bool
{
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    return $sessionToken !== '' && hash_equals($sessionToken, $token);
}
