{{-- Deklarasi XML ditambahkan ShowSitemap (aman dari short open tag). --}}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($entries as $entry)
    <url>
        <loc>{{ $entry['loc'] }}</loc>
@if ($entry['lastmod'])
        <lastmod>{{ $entry['lastmod'] }}</lastmod>
@endif
    </url>
@endforeach
</urlset>
