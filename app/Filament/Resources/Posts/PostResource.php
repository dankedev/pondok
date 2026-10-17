<?php

namespace App\Filament\Resources\Posts;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Resources\Posts\Pages\CreatePost;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Filament\Resources\Posts\Schemas\PostForm;
use App\Filament\Resources\Posts\Tables\PostsTable;
use App\Models\Post;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\UploadedFile;

/**
 * Berita, kegiatan, dan prestasi (F016). Simpan lewat `SavePost`, hapus dan
 * pulihkan lewat `DeletePost`/`RestorePost`. Konten tidak dibatasi jenjang.
 */
class PostResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Post::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 5;

    public static function getModule(): string
    {
        return 'konten';
    }

    public static function getModelLabel(): string
    {
        return __('pondok.post.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.post.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.situs');
    }

    public static function form(Schema $schema): Schema
    {
        return PostForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PostsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPosts::route('/'),
            'create' => CreatePost::route('/create'),
            'edit' => EditPost::route('/{record}/edit'),
        ];
    }

    /**
     * Post di tempat sampah tetap bisa dibuka untuk dipulihkan.
     */
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    /**
     * Data form untuk `SavePost`: berkas sampul diambil dari state FileUpload.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function actionData(array $data): array
    {
        $cover = $data['cover'] ?? null;

        if (is_array($cover)) {
            $cover = collect($cover)->first(fn (mixed $item): bool => $item instanceof UploadedFile);
        }

        return [...$data, 'cover' => $cover instanceof UploadedFile ? $cover : null];
    }
}
