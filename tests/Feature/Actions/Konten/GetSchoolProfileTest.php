<?php

use App\Actions\Konten\GetSchoolProfile;

it('AC-37: lists the school profile keys in one place', function () {
    expect(GetSchoolProfile::KEYS)->toContain(
        'school.name', 'school.tagline', 'school.logo_path', 'school.address', 'school.phone',
        'school.whatsapp', 'school.email', 'school.instagram_url', 'school.facebook_url', 'school.youtube_url',
    );
});
