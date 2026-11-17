<?php

declare(strict_types=1);

namespace App\Perfume\Services;

use App\Perfume\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Writes a product and its nested lists (categories, variants, images,
 * notes) from one validated payload, in one transaction.
 *
 * A nested list that is PRESENT replaces what is stored: rows are deleted
 * and re-inserted in payload order, which is also the new `position`. A
 * list that is ABSENT is untouched, so a PATCH of just `active` never
 * wipes the variants.
 */
class ProductWriter
{
    /** @param array<string, mixed> $data */
    public function create(array $data): Product
    {
        return DB::transaction(function () use ($data): Product {
            $product = Product::create($this->own($data));
            $this->syncNested($product, $data);

            return $product;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data): Product {
            $product->update($this->own($data));
            $this->syncNested($product, $data);

            return $product;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function own(array $data): array
    {
        unset($data['category_ids'], $data['variants'], $data['images'], $data['notes']);

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function syncNested(Product $product, array $data): void
    {
        if (array_key_exists('category_ids', $data)) {
            $product->categories()->sync($data['category_ids'] ?? []);
        }

        if (array_key_exists('variants', $data)) {
            $product->variants()->delete();

            foreach (array_values($data['variants'] ?? []) as $i => $variant) {
                $product->variants()->create([
                    'volume_ml' => $variant['volume_ml'],
                    'label'     => $variant['label'],
                    'price'     => $variant['price'] ?? null,
                    'old_price' => $variant['old_price'] ?? null,
                    'stock'     => $variant['stock'] ?? 0,
                    'is_sample' => (bool) ($variant['is_sample'] ?? false),
                    'position'  => $i,
                ]);
            }
        }

        if (array_key_exists('images', $data)) {
            $product->images()->delete();

            foreach (array_values($data['images'] ?? []) as $i => $image) {
                $product->images()->create([
                    'path'     => $image['path'],
                    'alt'      => $image['alt'] ?? null,
                    'position' => $i,
                ]);
            }
        }

        if (array_key_exists('notes', $data)) {
            $product->notes()->delete();
            $seen = [];

            foreach (array_values($data['notes'] ?? []) as $i => $note) {
                $key = $note['layer'] . ':' . $note['scent_note_id'];

                if (isset($seen[$key])) {
                    continue;   // the unique index would reject it; drop the duplicate instead
                }

                $seen[$key] = true;
                $product->notes()->create([
                    'scent_note_id' => $note['scent_note_id'],
                    'layer'         => $note['layer'],
                    'position'      => $i,
                ]);
            }
        }

        // The observer fires per row above; one more `touch` makes the
        // product's updated_at reflect nested edits for the sitemap.
        $product->touch();
    }
}
