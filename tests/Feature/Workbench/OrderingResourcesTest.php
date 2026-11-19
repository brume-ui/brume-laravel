<?php

use Brume\Http\Middleware\HandleBrumeRequests;
use Illuminate\Support\Facades\Route;
use Workbench\App\Http\Controllers\HomeBannerController;
use Workbench\App\Http\Controllers\NavMenuController;
use Workbench\App\Http\Controllers\ProductCategoryController;
use Workbench\App\Http\Controllers\ProductCategoryTreeController;
use Workbench\App\Models\HomeBanner;
use Workbench\App\Models\NavMenu;
use Workbench\App\Models\ProductCategory;
use Workbench\Database\Seeders\CatalogSeeder;
use Workbench\Database\Seeders\OrderingSeeder;

beforeEach(function () {
    $this->actingAs(brumeUser(), 'brume');
    Route::prefix('admin')->middleware([...config('brume.route.middleware'), HandleBrumeRequests::class])->group(function () {
        Route::brumeResource('nav-menus', NavMenuController::class)->names('brume.nav-menus');
        Route::brumeResource('home-banners', HomeBannerController::class)->names('brume.home-banners');
        Route::brumeResource('product-categories', ProductCategoryController::class)->names('brume.product-categories');
        Route::brumeResource('product-category-tree', ProductCategoryTreeController::class)->names('brume.product-category-tree');
    });
    Route::getRoutes()->refreshNameLookups();
    $this->seed(CatalogSeeder::class);
    $this->seed(OrderingSeeder::class);
});

function orderingPage($test, string $uri): array
{
    return $test->get($uri)->assertOk()->viewData('page')['props']['envelope']['page'];
}

it('shows the nav menus as a two-level tree', function () {
    $page = orderingPage($this, '/admin/nav-menus');

    expect($page['ordering'])->toMatchArray(['mode' => 'tree', 'column' => 'order', 'maxDepth' => 2])
        ->and($page['rows'])->toHaveCount(18)
        ->and($page['columns'][0])->toMatchArray(['key' => 'name', 'primary' => true])
        ->and($page['rows'][0]['name'])->toBe('關於我們')
        ->and($page['rows'][0]['_meta']['parent'])->toBeNull()
        ->and($page['rows'][1]['_meta']['parent'])->toBe($page['rows'][0]['id'])
        ->and(NavMenu::where('enabled', false)->count())->toBeGreaterThanOrEqual(1)
        ->and(NavMenu::whereNotNull('url')->count())->toBeGreaterThanOrEqual(1);
});

it('refuses to delete a menu that still has sub-menus', function () {
    $this->postJson('/admin/nav-menus/_brume/actions/delete', ['target' => 'row', 'ids' => [1]])
        ->assertOk()->assertJsonPath('feedback.toast.type', 'error')->assertJsonPath('feedback.toast.message', '「關於我們」底下還有子項，請先刪除或移走子項。');

    expect(NavMenu::whereKey(1)->exists())->toBeTrue();
});

it('offers only other root menus as a parent', function () {
    $options = fn (string $url) => array_column($this->getJson("{$url}/_brume/form-options?field=parent_id")->assertOk()->json('data'), 'value');

    expect($options('/admin/nav-menus'))->toBe(['1', '2', '3', '4', '5'])
        // Editing a sub-menu: all roots; a root with sub-menus must stay a root.
        ->and($options('/admin/nav-menus/6'))->toBe(['1', '2', '3', '4', '5'])
        ->and($options('/admin/nav-menus/1'))->toBe([]);
});

it('lists the banners 20 per page in drag order, rows without a sort value last', function () {
    $page = orderingPage($this, '/admin/home-banners');

    expect($page['ordering'])->toMatchArray(['mode' => 'list', 'column' => 'sort', 'direction' => 'asc'])
        ->and($page['pagination'])->toMatchArray(['perPage' => 20, 'total' => 45, 'lastPage' => 3])
        ->and($page['rows'])->toHaveCount(20)
        ->and(array_column($page['rows'], 'id'))->toBe(range(1, 20))
        ->and(HomeBanner::whereNull('sort')->count())->toBe(5);

    $last = $this->get('/admin/home-banners?page=3')->assertOk()->viewData('page')['props']['envelope']['page']['rows'];
    expect(array_column($last, 'id'))->toBe(range(41, 45));
});

it('reorders banners across pages through the reorder endpoint', function () {
    $this->postJson('/admin/home-banners/_brume/reorder', ['id' => 45, 'before' => 1])->assertOk();

    $page = orderingPage($this, '/admin/home-banners');
    expect(array_slice(array_column($page['rows'], 'id'), 0, 3))->toBe([45, 1, 2])
        ->and(HomeBanner::whereNull('sort')->count())->toBe(0);
});

it('moves a category in the tree and the 商品分類 list follows at once', function () {
    $page = orderingPage($this, '/admin/product-category-tree');
    expect($page['ordering'])->toMatchArray(['mode' => 'tree', 'column' => 'position', 'maxDepth' => 3])
        ->and(array_column($page['rows'], 'name'))->toBe(['服飾', '外套', '家居']);

    // 外套 (under 服飾) goes under 家居: its path, and every descendant's, must be rewritten.
    $grandchild = ProductCategory::create(['name' => '羽絨', 'parent_id' => 3]);
    $this->postJson('/admin/product-category-tree/_brume/reorder', ['id' => 3, 'parent' => 2])->assertOk();

    $list = orderingPage($this, '/admin/product-categories');
    expect(array_column($list['rows'], 'name'))->toBe(['服飾', '家居', '外套', '羽絨'])
        ->and(array_map(fn ($row) => $row['_meta']['depth']['name'], $list['rows']))->toBe([0, 0, 1, 2]);

    // 家居 before 服飾: both subtrees move in the old list.
    $this->postJson('/admin/product-category-tree/_brume/reorder', ['id' => 2, 'parent' => null, 'before' => 1])->assertOk();
    $list = orderingPage($this, '/admin/product-categories');
    expect(array_column($list['rows'], 'name'))->toBe(['家居', '外套', '羽絨', '服飾'])
        ->and(array_map(fn ($row) => $row['_meta']['depth']['name'], $list['rows']))->toBe([0, 1, 2, 0])
        ->and($grandchild->fresh()->tree_path)->toStartWith(ProductCategory::findOrFail(3)->tree_path);
});

it('seeds again without duplicating or disturbing other rows', function () {
    $counts = fn () => [NavMenu::count(), HomeBanner::count(), ProductCategory::count()];
    $before = $counts();
    $this->seed(OrderingSeeder::class);
    $this->seed(OrderingSeeder::class);

    expect($counts())->toBe($before)->and($before)->toBe([18, 45, 3])
        ->and(HomeBanner::find(45)->title)->toBeString();
});
