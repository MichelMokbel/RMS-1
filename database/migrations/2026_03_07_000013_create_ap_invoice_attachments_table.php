<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ap_invoice_attachments')) {
            Schema::create('ap_invoice_attachments', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('invoice_id');
                $table->string('file_path', 255);
                $table->string('original_name', 255)->nullable();
                $table->integer('uploaded_by')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index('invoice_id', 'ap_invoice_attachments_invoice_id_index');
                $table->index('uploaded_by', 'ap_invoice_attachments_uploaded_by_index');

                $table->foreign('invoice_id', 'ap_invoice_attachments_invoice_id_foreign')
                    ->references('id')
                    ->on('ap_invoices')
                    ->onDelete('cascade');
                $table->foreign('uploaded_by', 'ap_invoice_attachments_uploaded_by_foreign')
                    ->references('id')
                    ->on('users')
                    ->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ap_invoice_attachments')) {
            Schema::table('ap_invoice_attachments', function (Blueprint $table) {
                $table->dropForeign('ap_invoice_attachments_invoice_id_foreign');
                $table->dropForeign('ap_invoice_attachments_uploaded_by_foreign');
            });
        }

        Schema::dropIfExists('ap_invoice_attachments');
    }
};
