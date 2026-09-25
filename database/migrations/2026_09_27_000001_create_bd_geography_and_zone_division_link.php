<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Custom addition (not part of the original app): Bangladesh's 8 Divisions and their 64 Districts, as a small
 * reference geography so a Sale's Zone/Area can be linked to a Division for future Division-wise reporting.
 *
 * A Zone/Area is free text (delivery zones aren't always exactly a district name), so `sale_zones.division_id`
 * stays NULLABLE — a zone with no recognizable district in its name simply has no division, never a wrong guess.
 * `App\Support\BdDistrictMatcher` resolves a zone name to a division id by matching against each district's
 * canonical name AND its known alternate spellings (`aliases`, e.g. Bogra/Bogura, Jessore/Jashore, Cumilla/
 * Comilla) case-insensitively; it is used (a) as a live suggestion while typing a Zone name and (b) as the
 * server-side fallback when a Zone is created from the quick "+ add new" picker on the Sale form, which only ever
 * sends a name. A human can always accept, change or clear the suggested Division — it is never silently forced.
 */
return new class extends Migration
{
    /** Division name => list of [district name, aliases[]] pairs. Source: Bangladesh's official 8 divisions /
     *  64 districts (verified against en.wikipedia.org/wiki/Districts_of_Bangladesh, 2026-09-25). */
    private function data(): array
    {
        return [
            'Dhaka' => [
                ['Dhaka', []],
                ['Faridpur', []],
                ['Gazipur', []],
                ['Gopalganj', []],
                ['Kishoreganj', ['Kishorganj']],
                ['Madaripur', []],
                ['Manikganj', []],
                ['Munshiganj', ['Munshigonj']],
                ['Narayanganj', ['Narayangonj']],
                ['Narsingdi', []],
                ['Rajbari', []],
                ['Shariatpur', []],
                ['Tangail', []],
            ],
            'Chattogram' => [
                ['Bandarban', []],
                ['Brahmanbaria', ['Brahamanbaria']],
                ['Chandpur', []],
                ['Chattogram', ['Chittagong']],
                ['Cumilla', ['Comilla']],
                ["Cox's Bazar", ['Coxs Bazar', 'Cox Bazar', "Cox's Bazaar"]],
                ['Feni', []],
                ['Khagrachhari', ['Khagrachari']],
                ['Lakshmipur', ['Laxmipur']],
                ['Noakhali', []],
                ['Rangamati', []],
            ],
            'Rajshahi' => [
                ['Bogura', ['Bogra']],
                ['Joypurhat', ['Jaipurhat']],
                ['Naogaon', []],
                ['Natore', []],
                ['Chapainawabganj', ['Nawabganj', 'Chapai Nawabganj']],
                ['Pabna', []],
                ['Rajshahi', []],
                ['Sirajganj', []],
            ],
            'Khulna' => [
                ['Bagerhat', []],
                ['Chuadanga', []],
                ['Jashore', ['Jessore']],
                ['Jhenaidah', ['Jhenaida']],
                ['Khulna', []],
                ['Kushtia', []],
                ['Magura', []],
                ['Meherpur', []],
                ['Narail', []],
                ['Satkhira', []],
            ],
            'Barishal' => [
                ['Barguna', []],
                ['Barishal', ['Barisal']],
                ['Bhola', []],
                ['Jhalokati', ['Jhalokathi']],
                ['Patuakhali', []],
                ['Pirojpur', []],
            ],
            'Sylhet' => [
                ['Habiganj', []],
                ['Moulvibazar', ['Maulvibazar']],
                ['Sunamganj', []],
                ['Sylhet', []],
            ],
            'Rangpur' => [
                ['Dinajpur', []],
                ['Gaibandha', []],
                ['Kurigram', []],
                ['Lalmonirhat', []],
                ['Nilphamari', []],
                ['Panchagarh', ['Panchagar']],
                ['Rangpur', []],
                ['Thakurgaon', []],
            ],
            'Mymensingh' => [
                ['Jamalpur', []],
                ['Mymensingh', []],
                ['Netrokona', ['Netrakona']],
                ['Sherpur', []],
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('bd_divisions')) {
            Schema::create('bd_divisions', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('bd_districts')) {
            Schema::create('bd_districts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('division_id')->constrained('bd_divisions')->cascadeOnDelete();
                $table->string('name');
                $table->json('aliases')->nullable();
                $table->timestamps();
                $table->unique('name');
            });
        }

        if (! Schema::hasColumn('sale_zones', 'division_id')) {
            Schema::table('sale_zones', function (Blueprint $table) {
                $table->foreignId('division_id')->nullable()->after('name')
                    ->constrained('bd_divisions')->nullOnDelete();
            });
        }

        if ((int) DB::table('bd_divisions')->count() === 0) {
            $now = now();
            $order = 0;
            foreach ($this->data() as $divisionName => $districts) {
                $order++;
                $divisionId = DB::table('bd_divisions')->insertGetId([
                    'name' => $divisionName,
                    'sort_order' => $order,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                foreach ($districts as [$districtName, $aliases]) {
                    DB::table('bd_districts')->insert([
                        'division_id' => $divisionId,
                        'name' => $districtName,
                        'aliases' => $aliases === [] ? null : json_encode($aliases),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }

        // One-time backfill: link every EXISTING Zone/Area whose name recognizably matches a district (or one of
        // its aliases), case-insensitively. A zone that matches nothing keeps division_id = NULL — never a guess.
        if (Schema::hasTable('sale_zones')) {
            $lookup = [];
            foreach (DB::table('bd_districts')->get(['id', 'division_id', 'name', 'aliases']) as $district) {
                $names = array_merge([$district->name], $district->aliases ? json_decode($district->aliases, true) : []);
                foreach ($names as $n) {
                    $lookup[$this->normalize($n)] = $district->division_id;
                }
            }

            foreach (DB::table('sale_zones')->whereNull('division_id')->get(['id', 'name']) as $zone) {
                $divisionId = $lookup[$this->normalize($zone->name)] ?? null;
                if ($divisionId !== null) {
                    DB::table('sale_zones')->where('id', $zone->id)->update(['division_id' => $divisionId]);
                }
            }
        }
    }

    private function normalize(string $name): string
    {
        $name = mb_strtolower(trim($name));

        return preg_replace('/[^a-z0-9]+/', '', $name);
    }

    public function down(): void
    {
        if (Schema::hasColumn('sale_zones', 'division_id')) {
            Schema::table('sale_zones', function (Blueprint $table) {
                $table->dropForeign(['division_id']);
                $table->dropColumn('division_id');
            });
        }
        Schema::dropIfExists('bd_districts');
        Schema::dropIfExists('bd_divisions');
    }
};
