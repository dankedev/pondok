<?php

namespace App\Filament\AvatarProviders;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * F088: avatar inisial sebagai SVG data URI, pengganti UiAvatarsProvider bawaan Filament
 * yang mengirim inisial pengguna ke ui-avatars.com. Warna sama dengan bawaan Filament.
 */
class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $initials = e($this->initials(Filament::getNameForDefaultAvatar($record)));
        $background = Color::convertToHex(FilamentColor::getColor('gray')[950] ?? Color::Gray[950]);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">'
            ."<rect width=\"64\" height=\"64\" fill=\"{$background}\"/>"
            .'<text x="50%" y="50%" dy=".35em" text-anchor="middle" fill="#FFFFFF" '
            .'font-family="ui-sans-serif, system-ui, sans-serif" font-size="26" font-weight="600">'
            .$initials.'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * Huruf pertama dari maksimal dua kata, tanpa tanda baca di awal kata (mis. "[SYSTEM] Admin").
     */
    public function initials(string $name): string
    {
        $initials = str($name)
            ->squish()
            ->explode(' ')
            ->map(fn (string $word): string => mb_substr((string) preg_replace('/^[^\p{L}\p{N}]+/u', '', $word), 0, 1))
            ->filter()
            ->take(2)
            ->join('');

        return filled($initials) ? mb_strtoupper($initials) : '?';
    }
}
