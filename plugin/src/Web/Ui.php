<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Web;

/**
 * Building blocks shared by the WHM screens (View) and the cPanel app
 * (Client\ClientView): the Lucide icon set (ISC license), output escaping
 * and the A2 stylesheet. Both interfaces render the same markup classes,
 * so they share one stylesheet (assets/app.css).
 */
final class Ui
{
    /** Lucide icons (ISC license), the same paths as the design preview. */
    public const ICONS = [
        'transfer' => '<path d="m16 3 4 4-4 4"/><path d="M20 7H4"/><path d="m8 21-4-4 4-4"/><path d="M4 17h16"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'settings' => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
        'refresh' => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
        'check-circle' => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
        'activity' => '<path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/>',
        'eye' => '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
        'triangle' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'hourglass' => '<path d="M5 22h14"/><path d="M5 2h14"/><path d="M17 22v-4.172a2 2 0 0 0-.586-1.414L12 12l-4.414 4.414A2 2 0 0 0 7 17.828V22"/><path d="M7 2v4.172a2 2 0 0 0 .586 1.414L12 12l4.414-4.414A2 2 0 0 0 17 6.172V2"/>',
        'loader' => '<path d="M21 12a9 9 0 1 1-6.219-8.56"/>',
        'rotate' => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>',
        'alert-circle' => '<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>',
        'chevron' => '<path d="m6 9 6 6 6-6"/>',
        'card' => '<rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/>',
        'banknote' => '<rect width="20" height="12" x="2" y="6" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'external' => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
        'globe' => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
        'ban' => '<circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/>',
        'server' => '<rect width="20" height="8" x="2" y="2" rx="2" ry="2"/><rect width="20" height="8" x="2" y="14" rx="2" ry="2"/><line x1="6" x2="6.01" y1="6" y2="6"/><line x1="6" x2="6.01" y1="18" y2="18"/>',
        'arrow-left' => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
        'layers' => '<path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/><path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/>',
        'gift' => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5A4.8 8 0 0 1 12 8a4.8 8 0 0 1 4.5-5 2.5 2.5 0 0 1 0 5"/>',
        'receipt' => '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 17.5v-11"/>',
        'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
        'upload' => '<path d="M12 13v8"/><path d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"/><path d="m8 17 4-4 4 4"/>',
        'key' => '<path d="M2.586 17.414A2 2 0 0 0 2 18.828V21a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h.172a2 2 0 0 0 1.414-.586l.814-.814a6.5 6.5 0 1 0-4-4z"/><circle cx="16.5" cy="7.5" r=".5" fill="currentColor"/>',
        'plug' => '<path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"/><path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z"/>',
        'x-circle' => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
        'copy' => '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
        'history' => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>',
        'help-circle' => '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>',
        'shield' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
        'life-buoy' => '<circle cx="12" cy="12" r="10"/><path d="m4.93 4.93 4.24 4.24"/><path d="m14.83 9.17 4.24-4.24"/><path d="m14.83 14.83 4.24 4.24"/><path d="m9.17 14.83-4.24 4.24"/><circle cx="12" cy="12" r="4"/>',
        'mail' => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
        'map-pin' => '<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>',
        'sparkles' => '<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/><path d="M20 3v4"/><path d="M22 5h-4"/><path d="M4 17v2"/><path d="M5 18H3"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'arrow-right' => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
        'rocket' => '<path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/>',
        'user' => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'database' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/><path d="M3 12A9 3 0 0 0 21 12"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'x' => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'pin' => '<path d="M12 17v5"/><path d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z"/>',
        'pin-off' => '<path d="M12 17v5"/><path d="M15 9.34V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H7.89"/><path d="m2 2 20 20"/><path d="M9 9v1.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h11"/>',
        'megaphone' => '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
        'star' => '<path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/>',
        'party' => '<path d="M5.8 11.3 2 22l10.7-3.79"/><path d="M4 3h.01"/><path d="M22 8h.01"/><path d="M15 2h.01"/><path d="M22 20h.01"/><path d="m22 2-2.24.75a2.9 2.9 0 0 0-1.96 3.12c.1.86-.57 1.63-1.45 1.63h-.38c-.86 0-1.6.6-1.76 1.44L14 10"/><path d="m22 13-.82-.33c-.86-.34-1.82.2-1.98 1.11c-.11.7-.72 1.22-1.43 1.22H17"/><path d="m11 2 .33.82c.34.86-.2 1.82-1.11 1.98C9.52 4.9 9 5.52 9 6.23V7"/><path d="M11 13c1.93 1.93 2.83 4.17 2 5-.83.83-3.07-.07-5-2-1.93-1.93-2.83-4.17-2-5 .83-.83 3.07.07 5 2Z"/>',
    ];

    /** BladeCP "B" mark (viewBox 0 0 118.16 85.75), filled with the brand gradient. */
    public const MARK_PATH = 'M102.85,41.53c9.31-3.92,15.31-11.15,15.31-21.44,0-12.86-10.41-20.09-29.77-20.09h-43.37l-10.16,50.84h59.06l-1.85,8.61c-1.02,4.74-5.73,8.61-10.46,8.61h-2.08c-1.05.11-2.1.17-3.15.17l-53.49-.02c-12.61.86-19.71,7.25-22.89,17.54h75.03c22.05,0,39.32-8.57,39.32-27.2,0-7.96-4.17-13.84-11.52-17.03h.02ZM82.64,17.52c6.37,0,10.17,2.08,10.17,6.74,0,6.37-4.66,9.8-13.23,9.8h-17.4l3.31-16.54h17.15Z';
    /** BladeCP wordmark (viewBox 0 0 354.64 63.53), drawn with currentColor. */
    public const LOGO_PATHS = '<path d="M59.99,31.93c5.43-2.29,8.93-6.5,8.93-12.5,0-7.5-6.07-11.72-17.36-11.72h-25.3l-5.92,29.65h34.45l-1.08,5.02c-.59,2.76-3.34,5.02-6.1,5.02h-1.21c-.58.07-1.19.1-1.84.1H13.35c-7.36.49-11.49,4.21-13.35,10.22h43.77c12.86,0,22.94-5,22.94-15.86,0-4.64-2.43-8.07-6.72-9.93h0ZM48.2,17.93c3.72,0,5.93,1.22,5.93,3.93,0,3.72-2.72,5.72-7.72,5.72h-10.15l1.93-9.65h10ZM68.13,57.73h13.58l8.99-45.08c1.56-6.96-3.63-8.68-11.99-7.94l-10.58,53.02ZM123.15,19l-.93,4.64c-2.43-3.72-6.65-5.29-11.86-5.29-11.58,0-21.01,9.5-21.01,22.37,0,11.29,8,17.65,16.36,17.65,4.5,0,8.22-1.5,11.22-4.29l-.79,3.64h12.86l5.34-26.79c.47-8.04-4.16-11.15-11.2-11.94h0ZM110.43,47.44c-4.64,0-7.36-2.86-7.36-7.36,0-6.5,3.86-10.86,9.22-10.86,4.64,0,7.36,2.93,7.36,7.43,0,6.43-3.79,10.79-9.22,10.79ZM173.46,4.71l-3.79,18.79c-2.43-3.57-6.65-5.14-11.79-5.14-11.58,0-21.08,9.5-21.08,22.37,0,11.29,8,17.65,16.36,17.65,4.5,0,8.29-1.64,11.22-4.43l-.79,3.79h12.86l8.99-45.08c1.05-6.36-3.54-8.57-11.99-7.94h0ZM157.88,47.44c-4.64,0-7.29-2.86-7.29-7.36,0-6.5,3.79-10.86,9.22-10.86,4.64,0,7.29,2.93,7.29,7.43,0,6.43-3.79,10.79-9.22,10.79ZM226.55,35.36c0-10.15-7.29-17.01-19.08-17.01-13.93,0-23.44,9.5-23.44,22.37,0,10.79,8.15,17.65,20.58,17.65,5.86,0,11.65-1.43,16.51-5-1.49-3.61-4.62-7.53-10.81-5.88-9.28,2.46-12.49-1.48-12.99-5.76h28.44c.43-1.93.79-4.07.79-6.36h0ZM206.83,28c4.36,0,7.29,2.43,7.22,6.57h-15.93c1.29-3.86,4.14-6.57,8.72-6.57Z"/><path d="M263.7,63.53c10.91,0,19.9-3.06,26.79-11.35-2.46-4.91-6.02-7.84-16.8-4.85-.02,0-.04.02-.06.03-2.6,1.3-5.38,1.85-8.27,1.85-9.34,0-14.92-5.5-14.92-13.7,0-12.04,7.85-21.21,19.72-21.21,2.23,0,4.3.34,6.17,1.04.01,0,.02,0,.03.01,11.94,4.52,17.95-1.84,19.72-3.85-4.36-7.42-13.53-11.52-24.79-11.52-22.69,0-38.31,15.45-38.31,36.65,0,15.97,11.96,26.88,30.72,26.88h0ZM329.85,1.22h-25.83l-12.22,61.09c9.35.01,17.19-3.15,20.42-15.97h12.22c18.42,0,30.2-10.04,30.2-25.48,0-12.39-8.91-19.64-24.79-19.64h0ZM325.23,32.73h-10.3l3.58-17.89h9.08c6.02,0,9.6,2.36,9.6,7.51,0,6.72-4.37,10.39-11.96,10.39h0Z" fill-rule="evenodd"/>';

    private static ?string $stylesheet = null;
    private static int $marks = 0;

    public static function e(mixed $value): string
    {
        return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /** The BladeCP "B" with its gradient; each copy gets its own gradient id. */
    public static function mark(string $class = 'm-mark', string $label = ''): string
    {
        $id = 'bcpm-b-' . ++self::$marks;
        return '<svg class="' . $class . '" viewBox="0 0 118.16 85.75"' . ($label !== '' ? ' role="img" aria-label="' . self::e($label) . '"' : ' aria-hidden="true"')
            . ' focusable="false"><defs><linearGradient id="' . $id . '" x1="47.26" y1="44.94" x2="145.43" y2="46.35" gradientTransform="translate(0 88) scale(1 -1)"'
            . ' gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#8b5cf6"/><stop offset=".7" stop-color="#636ff6"/><stop offset="1" stop-color="#3b82f6"/></linearGradient>'
            . '</defs><path fill="url(#' . $id . ')" d="' . self::MARK_PATH . '"/></svg>';
    }

    /** The BladeCP wordmark, in the color of its container. */
    public static function logo(string $class = 'm-logo'): string
    {
        return '<svg class="' . $class . '" viewBox="0 0 354.64 63.53" fill="currentColor" role="img" aria-label="BladeCP">' . self::LOGO_PATHS . '</svg>';
    }

    /** The "B" as an SVG data URI, for the page icon (img-src allows data:). */
    public static function favicon(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><defs><linearGradient id="g" x1="47.26" y1="44.94" x2="145.43" y2="46.35"'
            . ' gradientTransform="translate(0 88) scale(1 -1)" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#8b5cf6"/><stop offset=".7" stop-color="#636ff6"/>'
            . '<stop offset="1" stop-color="#3b82f6"/></linearGradient></defs><g transform="translate(2.5 8.3) scale(.3639)"><path fill="url(#g)" d="' . self::MARK_PATH . '"/></g></svg>';
        return 'data:image/svg+xml,' . rawurlencode($svg);
    }

    public static function icon(string $name, string $class = ''): string
    {
        return '<svg' . ($class !== '' ? ' class="' . $class . '"' : '') . ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
            . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . (self::ICONS[$name] ?? '') . '</svg>';
    }

    /**
     * The A2 stylesheet, inlined in every page (style-src 'unsafe-inline'
     * allows no external file). Comments are dropped: they are notes for
     * the code, not for the page, and the cPanel app never names the
     * migration platform.
     */
    public static function stylesheet(): string
    {
        if (self::$stylesheet === null) {
            $css = (string) @file_get_contents(BLADECP_MIGRATOR_ROOT . '/assets/app.css');
            self::$stylesheet = trim((string) preg_replace(['#/\*.*?\*/#s', '/\n{2,}/'], ['', "\n"], $css));
        }
        return self::$stylesheet;
    }
}
