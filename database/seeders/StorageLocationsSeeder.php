<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Business;
use App\Models\Branch;
use App\Models\StorageLocation;

class StorageLocationsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('========================================');
        $this->command->info('  Seeding Storage Locations...');
        $this->command->info('========================================');
        $this->command->newLine();

        $business = Business::first();

        if (!$business) {
            $this->command->error('No business found. Run InitialSetupSeeder first.');
            return;
        }

        $branches = Branch::where('business_id', $business->id)->get();

        if ($branches->isEmpty()) {
            $this->command->error('No branches found. Run InitialSetupSeeder first.');
            return;
        }

        $locationCount = 0;

        foreach ($branches as $branch) {
            $this->command->info("Creating storage locations for: {$branch->name}");

            // Different storage structure based on branch type
            if (str_contains(strtolower($branch->name), 'warehouse')) {
                // Warehouse has more extensive storage
                $zones = ['A', 'B', 'C', 'D'];
                $aislesPerZone = 4;
                $shelvesPerAisle = 5;
                $branchCode = $branch->code;

                foreach ($zones as $zone) {
                    // Create Zone
                    $zoneLocation = StorageLocation::create([
                        'business_id' => $business->id,
                        'branch_id' => $branch->id,
                        'name' => "Zone {$zone}",
                        'code' => "{$branchCode}-Z{$zone}",
                        'location_type' => 'zone',
                        'capacity' => 1000,
                        'description' => "Main storage zone {$zone}",
                        'is_active' => true,
                    ]);
                    $locationCount++;
                    $this->command->info("  ✓ Created zone: Zone {$zone}");

                    // Create Aisles in this Zone
                    for ($a = 1; $a <= $aislesPerZone; $a++) {
                        $aisleLocation = StorageLocation::create([
                            'business_id' => $business->id,
                            'branch_id' => $branch->id,
                            'name' => "Aisle {$zone}{$a}",
                            'code' => "{$branchCode}-Z{$zone}-A{$a}",
                            'location_type' => 'aisle',
                            'capacity' => 200,
                            'description' => "Aisle {$a} in Zone {$zone}",
                            'is_active' => true,
                        ]);
                        $locationCount++;

                        // Create Shelves in this Aisle
                        for ($s = 1; $s <= $shelvesPerAisle; $s++) {
                            StorageLocation::create([
                                'business_id' => $business->id,
                                'branch_id' => $branch->id,
                                'name' => "Shelf {$zone}{$a}-{$s}",
                                'code' => "{$branchCode}-Z{$zone}-A{$a}-S{$s}",
                                'location_type' => 'shelf',
                                'capacity' => 50,
                                'description' => "Shelf {$s} in Aisle {$zone}{$a}",
                                'is_active' => true,
                            ]);
                            $locationCount++;
                        }
                    }
                }

                // Add some special storage areas for warehouse
                $branchCode = $branch->code;
                $specialAreas = [
                    ['name' => 'Cold Storage', 'code' => "{$branchCode}-COLD-01", 'type' => 'cold_storage', 'capacity' => 500],
                    ['name' => 'Dry Storage', 'code' => "{$branchCode}-DRY-01", 'type' => 'dry_storage', 'capacity' => 800],
                    ['name' => 'Electronics Section', 'code' => "{$branchCode}-ELEC-01", 'type' => 'zone', 'capacity' => 300],
                    ['name' => 'Receiving Bay', 'code' => "{$branchCode}-RCV-01", 'type' => 'other', 'capacity' => 200],
                    ['name' => 'Dispatch Area', 'code' => "{$branchCode}-DISP-01", 'type' => 'other', 'capacity' => 200],
                ];

                foreach ($specialAreas as $area) {
                    StorageLocation::create([
                        'business_id' => $business->id,
                        'branch_id' => $branch->id,
                        'name' => $area['name'],
                        'code' => $area['code'],
                        'location_type' => $area['type'],
                        'capacity' => $area['capacity'],
                        'description' => "Special storage: {$area['name']}",
                        'is_active' => true,
                    ]);
                    $locationCount++;
                    $this->command->info("  ✓ Created special area: {$area['name']}");
                }

            } else {
                // Main/Retail branch has simpler storage
                $sections = ['Electronics', 'Food & Beverages', 'General'];
                $shelvesPerSection = 3;
                $branchCode = $branch->code;

                foreach ($sections as $index => $section) {
                    $sectionCode = "{$branchCode}-SEC" . ($index + 1);

                    // Create Section (Zone)
                    $sectionLocation = StorageLocation::create([
                        'business_id' => $business->id,
                        'branch_id' => $branch->id,
                        'name' => $section,
                        'code' => $sectionCode,
                        'location_type' => 'zone',
                        'capacity' => 300,
                        'description' => "{$section} section",
                        'is_active' => true,
                    ]);
                    $locationCount++;
                    $this->command->info("  ✓ Created section: {$section}");

                    // Create Shelves in this Section
                    for ($s = 1; $s <= $shelvesPerSection; $s++) {
                        StorageLocation::create([
                            'business_id' => $business->id,
                            'branch_id' => $branch->id,
                            'name' => "{$section} - Shelf {$s}",
                            'code' => "{$sectionCode}-S{$s}",
                            'location_type' => 'shelf',
                            'capacity' => 100,
                            'description' => "Shelf {$s} in {$section}",
                            'is_active' => true,
                        ]);
                        $locationCount++;
                    }
                }

                // Add retail-specific areas
                $branchCode = $branch->code;
                $retailAreas = [
                    ['name' => 'Display Counter', 'code' => "{$branchCode}-DISP-01", 'type' => 'other', 'capacity' => 50],
                    ['name' => 'Back Room Storage', 'code' => "{$branchCode}-BACK-01", 'type' => 'warehouse', 'capacity' => 150],
                    ['name' => 'POS Area', 'code' => "{$branchCode}-POS-01", 'type' => 'other', 'capacity' => 30],
                ];

                foreach ($retailAreas as $area) {
                    StorageLocation::create([
                        'business_id' => $business->id,
                        'branch_id' => $branch->id,
                        'name' => $area['name'],
                        'code' => $area['code'],
                        'location_type' => $area['type'],
                        'capacity' => $area['capacity'],
                        'description' => "Retail area: {$area['name']}",
                        'is_active' => true,
                    ]);
                    $locationCount++;
                    $this->command->info("  ✓ Created retail area: {$area['name']}");
                }
            }

            $this->command->newLine();
        }

        $this->command->info('========================================');
        $this->command->info('  Storage Locations Seeded Successfully!');
        $this->command->info('========================================');
        $this->command->info("  Total Locations Created: {$locationCount}");
        $this->command->newLine();
    }
}
