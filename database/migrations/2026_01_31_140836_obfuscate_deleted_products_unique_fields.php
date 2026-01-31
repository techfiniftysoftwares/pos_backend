<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     * 
     * Updates soft-deleted products to have obfuscated SKU/barcode values
     * to prevent unique constraint conflicts with new products.
     */
    public function up(): void
    {
        // Get all soft-deleted products that don't already have the _deleted_ suffix
        $deletedProducts = DB::table('products')
            ->whereNotNull('deleted_at')
            ->where('sku', 'NOT LIKE', '%_deleted_%')
            ->get();

        foreach ($deletedProducts as $product) {
            $deletedSuffix = '_deleted_' . strtotime($product->deleted_at);

            DB::table('products')
                ->where('id', $product->id)
                ->update([
                    'sku' => $product->sku . $deletedSuffix,
                    'barcode' => $product->barcode ? $product->barcode . $deletedSuffix : null,
                ]);
        }
    }

    /**
     * Reverse the migrations.
     * 
     * Removes the _deleted_ suffix from soft-deleted products.
     */
    public function down(): void
    {
        $deletedProducts = DB::table('products')
            ->whereNotNull('deleted_at')
            ->where('sku', 'LIKE', '%_deleted_%')
            ->get();

        foreach ($deletedProducts as $product) {
            // Remove the _deleted_{timestamp} suffix
            $originalSku = preg_replace('/_deleted_\d+$/', '', $product->sku);
            $originalBarcode = $product->barcode
                ? preg_replace('/_deleted_\d+$/', '', $product->barcode)
                : null;

            DB::table('products')
                ->where('id', $product->id)
                ->update([
                    'sku' => $originalSku,
                    'barcode' => $originalBarcode,
                ]);
        }
    }
};
