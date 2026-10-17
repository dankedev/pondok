<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\SavesProfileSettings;
use App\Models\User;

/**
 * Simpan footer portal sebagai daftar widget (F046) di `portal.footer_widgets`: paling banyak
 * 4 widget (termasuk yang tersembunyi) bertipe `text`, `links`, `social`, atau `contacts`,
 * divalidasi lewat `NormalizeContentBlocks` area `footer`. Log `portal_footer_updated` hanya
 * bila ada perubahan.
 */
class UpdatePortalFooter
{
    use SavesProfileSettings;

    public const KEY = 'portal.footer_widgets';

    public function __construct(private readonly NormalizeContentBlocks $normalize) {}

    /**
     * @param  array{footer_widgets?: mixed}  $data
     */
    public function handle(array $data, User $actor): void
    {
        $widgets = $this->normalize->handle('footer', $data['footer_widgets'] ?? []);

        $this->saveProfileSettings([self::KEY => $widgets], 'portal_footer_updated', $actor);
    }
}
