<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_documents', function (Blueprint $table) {
            $table->id();
            $table->string('type', 64)->index();
            $table->string('version', 32);
            $table->string('title');
            $table->longText('content');
            $table->string('content_hash', 64);
            $table->timestamp('published_at')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->unique(['type', 'version']);
        });

        Schema::create('legal_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('legal_document_id')->constrained('legal_documents')->restrictOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained('shipments')->nullOnDelete();
            $table->timestamp('accepted_at');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('content_hash', 64);
            $table->timestamp('created_at')->nullable();
            $table->index('shipment_id');
        });

        $content = implode("\n", [
            'Sustancias ilegales y narcóticos',
            'Armas de fuego, municiones y explosivos',
            'Materiales inflamables o peligrosos',
            'Dinero en efectivo, joyas y valores negociables',
            'Perecederos sin empaque adecuado',
            'Animales vivos',
            'Mercancía falsificada o de contrabando',
        ]);

        DB::table('legal_documents')->insert([
            'type' => 'prohibited_items',
            'version' => 'v1.0-provisional',
            'title' => 'Artículos prohibidos',
            'content' => $content,
            'content_hash' => hash('sha256', $content),
            'published_at' => now(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_acceptances');
        Schema::dropIfExists('legal_documents');
    }
};
