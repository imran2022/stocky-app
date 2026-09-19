<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Shared safe-upload helpers for user-attached business documents
 * (sale / purchase / purchase-order / expense attachments).
 *
 * Security fix (Build N1, audit finding C-03): these upload endpoints
 * previously accepted ANY file type ('required|file|max:10240' with no
 * mimes/extensions rule) and stored it directly under public/images/...,
 * which let an authenticated user upload a .php (or other executable)
 * file straight into the public webroot.
 *
 * This class enforces an extension allow-list (validated by Laravel's
 * `mimes:` rule, which checks BOTH the file extension and the file's
 * actual detected content type — a renamed .php file will fail this even
 * if given a .pdf extension) and always generates the on-disk filename
 * from the VALIDATED extension only, never from the client-supplied
 * original filename. That means a "invoice.pdf.php" double-extension
 * trick can never produce a .php (or any non-allow-listed) file on disk.
 */
class SafeDocumentUpload
{
    /**
     * Extensions business documents are allowed to be. Deliberately
     * excludes anything a web server could ever execute (php, phtml,
     * cgi, pl, ...) plus svg/html/js, which can carry active content.
     */
    public const ALLOWED_EXTENSIONS = [
        'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp',
        'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'zip',
    ];

    /**
     * Validation rule string for `documents.*` (or a single file field).
     * Use this in place of the old 'required|file|max:10240'.
     */
    public static function validationRule(): string
    {
        return 'required|file|max:10240|mimes:'.implode(',', self::ALLOWED_EXTENSIONS);
    }

    /**
     * A safe on-disk filename: fixed prefix + timestamp + random token +
     * the VALIDATED extension (lowercased). Never derived from the
     * client-supplied original filename, so no double-extension or
     * null-byte trick can smuggle an executable extension onto disk.
     */
    public static function safeFilename(UploadedFile $file, string $prefix = 'doc'): string
    {
        $ext = strtolower((string) $file->getClientOriginalExtension());

        if (! in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            // Should be unreachable once validationRule() has already run,
            // but never fall back to an unsanitized/unexpected extension.
            $ext = 'bin';
        }

        return $prefix.'_'.time().'_'.Str::random(16).'.'.$ext;
    }
}
