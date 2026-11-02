{{--
    Komponen `footer_widgets`: widget footer inti (`GetPortalFooter`) dengan gap dari gaya node. Mode ringkas (route `psb.*`):
    nama dan tautan widget Tautan pertama. Tautan di atas latar navy memakai kelas tetap `GetPortalFooter::LINK_CLASS`.
--}}
@if ($compact)
    <div class="space-y-3">
        <p class="text-base font-bold">{{ $school['name'] }}</p>
        @if ($footer['compactLinks'] !== [])
            <ul class="flex flex-wrap gap-x-6" aria-label="{{ __('pondok.portal.footer.links') }}">
                @foreach ($footer['compactLinks'] as $link)
                    <li><a href="{{ $link['url'] }}" class="{{ \App\Actions\Konten\GetPortalFooter::LINK_CLASS }}">{{ $link['label'] }}</a></li>
                @endforeach
            </ul>
        @endif
    </div>
@elseif ($footer['widgets'] !== [])
    @include('portal.partials.footer-widgets', ['gridClass' => 'grid '.\App\Actions\Konten\ThemeStyleMap::classes($node['style'] ?? [])])
@endif
