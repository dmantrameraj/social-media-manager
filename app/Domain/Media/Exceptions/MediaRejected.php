<?php

declare(strict_types=1);

namespace App\Domain\Media\Exceptions;

use RuntimeException;

/**
 * An upload was refused.
 *
 * Messages are written for the person uploading -- "Files must be smaller than
 * 100 MB" rather than a validation code -- but never reveal why a file looked
 * suspicious beyond what the user needs to fix it.
 */
final class MediaRejected extends RuntimeException {}
