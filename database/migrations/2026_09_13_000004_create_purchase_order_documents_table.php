<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PO attachments (supplier quotes, signed agreements, etc). Mirrors
 * purchase_documents exactly — same columns, same soft-delete-then-download
 * lifecycle — so PurchaseOrderController's attachment endpoints can reuse
 * PurchasesController's getDocuments/uploadDocuments/downloadDocument/
 * deleteDocument logic almost verbatim, just pointed at this table and the
 * `purchase_order_documents` storage folder instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_documents', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('id', true);
            $table->integer('purchase_order_id')->index('po_documents_po_id');
            $table->string('name', 255);
            $table->string('path', 500);
            $table->bigInteger('size')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->timestamps(6);
            $table->softDeletes();
        });

        Schema::table('purchase_order_documents', function (Blueprint $table) {
            $table->foreign('purchase_order_id', 'po_documents_po_id_foreign')
                ->references('id')
                ->on('purchase_orders')
                ->onUpdate('RESTRICT')
                ->onDelete('CASCADE');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_documents');
    }
};
