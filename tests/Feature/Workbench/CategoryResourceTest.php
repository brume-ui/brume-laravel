<?php

use Brume\Http\Middleware\HandleBrumeRequests;
use Illuminate\Support\Facades\Route;
use Workbench\App\Http\Controllers\ProductCategoryController;
use Workbench\App\Http\Controllers\ProductController;
use Workbench\App\Models\CatalogProduct;
use Workbench\App\Models\ProductCategory;
use Workbench\Database\Seeders\CatalogSeeder;

beforeEach(function () {
    $this->actingAs(brumeUser(), 'brume');
    Route::prefix('admin')->middleware([...config('brume.route.middleware'), HandleBrumeRequests::class])->group(function () {
        Route::brumeResource('product-categories', ProductCategoryController::class)->names('brume.product-categories');
        Route::brumeResource('products', ProductController::class)->names('brume.products');
    });
    Route::getRoutes()->refreshNameLookups();
    $this->seed(CatalogSeeder::class);
});

function categoryValues($test, int $id): array
{
    return $test->getJson("/admin/product-categories/{$id}/edit", ['X-Brume-Form' => '1'])->assertOk()->json('page.values');
}

function categoryOptions($test, string $url, array $query): array
{
    return $test->getJson("{$url}/_brume/form-options?".http_build_query($query))->assertOk()->json('data');
}

it('moves selected products from another category and nulls deselected ones', function () {
    [$a, $b] = [ProductCategory::findOrFail(1), ProductCategory::findOrFail(2)];
    $moving = CatalogProduct::where('category_id', $b->id)->firstOrFail();
    $kept = $a->products()->firstOrFail();
    $dropped = $a->products()->where('id', '!=', $kept->id)->firstOrFail();
    $count = CatalogProduct::count();

    $values = categoryValues($this, $a->id);
    $values['products'] = [['id' => (string) $kept->id], ['id' => (string) $moving->id]];
    $this->putJson("/admin/product-categories/{$a->id}", ['values' => $values])->assertOk();

    expect($moving->fresh()->category_id)->toBe($a->id)
        ->and($dropped->fresh()->category_id)->toBeNull()
        ->and($kept->fresh()->category_id)->toBe($a->id)
        ->and(CatalogProduct::count())->toBe($count)
        ->and(CatalogProduct::whereKey($dropped->id)->exists())->toBeTrue()
        ->and($b->products()->whereKey($moving->id)->exists())->toBeFalse();
});

it('pages the product source and rejects out-of-scope ids', function () {
    $category = ProductCategory::findOrFail(1);
    $url = "/admin/product-categories/{$category->id}";
    $ids = fn (int $page) => array_column(categoryOptions($this, $url, ['field' => 'products', 'page' => $page]), 'value');

    $first = $ids(1);
    $second = $ids(2);
    expect($first)->not->toBeEmpty()->and($second)->not->toBeEmpty()->and($second[0])->not->toBe($first[0])
        ->and(array_intersect($first, $second))->toBe([]);

    $before = $category->products()->pluck('id')->sort()->values()->all();
    $values = categoryValues($this, $category->id);
    $values['products'] = [['id' => '999999']];
    $this->putJson($url, ['values' => $values])->assertUnprocessable()->assertJsonValidationErrors('products');
    expect($category->products()->pluck('id')->sort()->values()->all())->toBe($before);
});

it('rejects a parent that is the category itself or a descendant', function () {
    $root = ProductCategory::whereNull('parent_id')->whereHas('children')->firstOrFail();
    $child = $root->children()->firstOrFail();
    $url = "/admin/product-categories/{$root->id}";
    $options = array_column(categoryOptions($this, $url, ['field' => 'parent_id']), 'value');

    expect($options)->not->toContain((string) $root->id)->and($options)->not->toContain((string) $child->id);

    foreach ([$root->id, $child->id] as $forged) {
        $values = categoryValues($this, $root->id);
        $values['parent_id'] = (string) $forged;
        $this->putJson($url, ['values' => $values])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    }
    expect($root->fresh()->parent_id)->toBeNull();
});

it('lists the hierarchy with indented names and product counts', function () {
    $page = $this->get('/admin/product-categories')->assertOk()->viewData('page')['props']['envelope']['page'];
    expect(array_column($page['columns'], 'key'))->toContain('name', 'products_count')
        ->and($page['rows'])->toHaveCount(ProductCategory::count())
        // Indentation only: the edit link carries no tree glyph.
        ->and(array_column($page['rows'], 'name'))->toBe(['服飾', '　　外套', '家居']);
});

function categoryField(array $nodes, string $name): ?array
{
    foreach ($nodes as $node) {
        if (($node['name'] ?? null) === $name && isset($node['type'])) {
            return $node;
        }
        if (is_array($node) && ($found = categoryField(array_values(array_filter($node, 'is_array')), $name)) !== null) {
            return $found;
        }
    }

    return null;
}

function createCategory($test, string $name, ?int $parentId): ProductCategory
{
    $values = array_replace($test->getJson('/admin/product-categories/create', ['X-Brume-Form' => '1'])->assertOk()->json('page.values'),
        ['name' => $name, 'parent_id' => $parentId === null ? null : (string) $parentId]);
    $test->postJson('/admin/product-categories', ['values' => $values])->assertOk();

    return ProductCategory::where('name', $name)->firstOrFail();
}

it('orders the grid and both category selects depth-first, a new category last among its siblings', function () {
    $root = createCategory($this, '新分類', null);
    $child = createCategory($this, '童裝', 1);
    $grandchild = createCategory($this, '羽絨', 3);
    $tree = [[1, 0], [3, 1], [$grandchild->id, 2], [$child->id, 1], [2, 0], [$root->id, 0]];
    $ids = array_map(fn ($node) => (string) $node[0], $tree);

    $rows = $this->get('/admin/product-categories')->assertOk()->viewData('page')['props']['envelope']['page']['rows'];
    expect(array_map(fn ($row) => (string) $row['id'], $rows))->toBe($ids)
        ->and(array_column($rows, 'name'))->toBe(['服飾', '　　外套', '　　　　羽絨', '　　童裝', '家居', '新分類']);

    $options = fn (string $url, string $field) => array_map(fn ($option) => [$option['value'], $option['depth']], categoryOptions($this, $url, ['field' => $field]));
    $expected = array_map(fn ($node) => [(string) $node[0], $node[1]], $tree);
    expect($options('/admin/products', 'category_id'))->toBe($expected)
        ->and($options('/admin/product-categories', 'parent_id'))->toBe($expected)
        // Editing 外套 hides itself and its subtree; the rest keeps the tree order.
        ->and($options('/admin/product-categories/3', 'parent_id'))->toBe(array_values(array_filter($expected, fn ($node) => ! in_array($node[0], ['3', (string) $grandchild->id], true))));
});

it('declares a clearable 無（頂層） parent choice that moves a sub-category back to the top level', function () {
    $field = categoryField($this->getJson('/admin/product-categories/3/edit', ['X-Brume-Form' => '1'])->assertOk()->json('page.form.nodes'), 'parent_id');
    expect($field)->toMatchArray(['clearable' => true, 'placeholder' => '無（頂層）']);

    $values = categoryValues($this, 3);
    $values['parent_id'] = null;
    $this->putJson('/admin/product-categories/3', ['values' => $values])->assertOk();

    $rows = $this->get('/admin/product-categories')->assertOk()->viewData('page')['props']['envelope']['page']['rows'];
    expect(ProductCategory::findOrFail(3)->parent_id)->toBeNull()
        ->and(array_column($rows, 'name'))->toBe(['服飾', '外套', '家居']);
});

it('refuses to delete a category that has sub-categories', function () {
    $this->postJson('/admin/product-categories/_brume/actions/delete', ['target' => 'row', 'ids' => [1]])
        ->assertOk()->assertJsonPath('feedback.toast.type', 'error')->assertJsonPath('feedback.toast.message', '「服飾」底下還有子分類，請先刪除或移走子分類。');

    expect(ProductCategory::whereKey([1, 3])->count())->toBe(2)
        ->and(CatalogProduct::where('category_id', 1)->count())->toBeGreaterThan(0);
});

it('deleting a category leaves its products uncategorised, and later saves of them still pass', function () {
    $products = CatalogProduct::where('category_id', 3)->pluck('id')->all();
    expect($products)->not->toBeEmpty();

    $this->postJson('/admin/product-categories/_brume/actions/delete', ['target' => 'row', 'ids' => [3]])->assertOk();

    expect(ProductCategory::whereKey(3)->exists())->toBeFalse()
        ->and(CatalogProduct::whereKey($products)->count())->toBe(count($products))
        ->and(CatalogProduct::whereKey($products)->whereNotNull('category_id')->count())->toBe(0);

    // The parent (服飾) has no children now, so it can go too, and its own products follow suit.
    $this->postJson('/admin/product-categories/_brume/actions/delete', ['target' => 'row', 'ids' => [1]])->assertOk();
    expect(ProductCategory::whereKey(1)->exists())->toBeFalse()->and(CatalogProduct::where('category_id', 1)->count())->toBe(0);

    $product = CatalogProduct::findOrFail($products[0]);
    $values = $this->getJson("/admin/products/{$product->id}/edit", ['X-Brume-Form' => '1'])->assertOk()->json('page.values');
    $this->putJson("/admin/products/{$product->id}", ['values' => array_replace($values, ['name' => '改名'])])->assertOk();
    expect($product->fresh()->name)->toBe('改名');
});
