<?php

use App\Models\Applicant;
use App\Models\Student;
use App\Models\User;

beforeEach(function () {
    portalBoot();
});

it('AC-33: does not expose personal data', function () {
    $student = Student::factory()->create(['name' => 'Zainab Rahmawati', 'nis' => '2026009911']);
    $applicant = Applicant::factory()->create(['name' => 'Fathimah Azzahra', 'registration_no' => 'PSB-2027-0001']);
    $author = User::factory()->create(['name' => 'Admin Konten Satu']);
    portalPost(['title' => 'Berita Privasi', 'author_id' => $author->id]);
    portalPost(['title' => 'Tanpa Penulis', 'author_id' => null]);

    foreach (['/', '/berita', '/berita/berita-privasi', '/sitemap.xml'] as $url) {
        $html = $this->get($url)->assertOk()->getContent();

        foreach ([$student->name, $student->nis, $applicant->name, 'PSB-2027-0001', 'Admin Konten Satu'] as $secret) {
            expect($html)->not->toContain((string) $secret);
        }
    }

    $this->get('/berita/tanpa-penulis')->assertOk();
});
