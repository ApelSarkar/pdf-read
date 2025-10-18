<?php

namespace App\Assistants;

use App\GeonamesCountry;
use Carbon\Carbon;
use Illuminate\Support\Str;

class CustomPdfAssistant extends PdfClient
{
    const PACKAGE_TYPE_MAP = [
        "EW-Paletten" => "PALLET_OTHER",
        "Ladung" => "CARTON",
        "Stück" => "OTHER",
    ];

    public static function validateFormat(array $lines)
    {
        return $lines[0] == "Access Logistic GmbH, Amerling 130, A-6233 Kramsach"
            && $lines[2] == "To:"
            && Str::startsWith($lines[4], "Contactperson: ");
    }

    public function processLines(array $lines, ?string $attachment_filename = null)
    {
        $tour_li = array_find_key($lines, fn($l) => $l == "Tournumber:");
        $order_reference = trim($lines[$tour_li + 2], '* ');
        // $order_reference = trim($lines[$tour_li + 1], '* ');


        $truck_li = array_find_key($lines, fn($l) => $l == "Truck, trailer:");
        $truck_number = $lines[$truck_li + 2] ?? null;
        // $truck_number = $lines[$truck_li + 1] ?? null;

        $vehicle_li = array_find_key($lines, fn($l) => $l == "Vehicle type:");
        $trailer_number = null;
        if ($truck_li && $vehicle_li) {
            $trailer_li = array_find_key($lines, fn($l, $i) => $i > $truck_li && $i < $vehicle_li && preg_match('/^[A-Z]{2}[0-9]{3}( |$)/', $l));
            $trailer_number = isset($trailer_li) ? explode(' ', $lines[$trailer_li], 2)[0] ?? null : null;
        }

        $transport_numbers = join(' / ', array_filter([$truck_number, $trailer_number]));

        $freight_li = array_find_key($lines, fn($l) => $l == "Freight rate in €:");
        // $freight_price = $lines[$freight_li + 2] ?? null;
        $freight_price = $lines[$freight_li + 2] ?? null;
        $freight_price = preg_replace('/[^0-9,\.]/', '', $freight_price);
        $freight_price = uncomma($freight_price);
        // if ($freight_price === null) {
        //     $freight_price = 0;
        // }
        $freight_currency = 'EUR';

        $loading_li = array_find_key($lines, fn($l) => $l == "Loading sequence:");
        $unloading_li = array_find_key($lines, fn($l) => $l == "Unloading sequence:");
        $regards_li = array_find_key($lines, fn($l) => $l == "Best regards");

        // $loading_locations = $this->extractLocations(
        //     array_slice($lines, $loading_li + 1, max(0, ($unloading_li - 1 - $loading_li)))
        // );

        $loading_locations = $this->extractLocations(
            array_slice($lines, $loading_li + 1, max(0, ($unloading_li - 1 - $loading_li)))
        );
        $destination_locations = $this->extractLocations(
            array_slice($lines, $unloading_li + 1, max(0, ($regards_li - 1 - $unloading_li)))
        );

        $contact_li = array_find_key($lines, fn($l) => Str::startsWith($l, 'Contactperson: '));
        $contact = $contact_li !== null ? explode(': ', $lines[$contact_li], 2)[1] ?? null : null;

        $customer = [
            'side' => 'none',
            'details' => [
                'company' => 'Access Logistic GmbH',
                'street_address' => 'Amerling 130',
                'city' => 'Kramsach',
                'postal_code' => '6233',
                'country' => 'AT',
                'vat_code' => 'ATU74076812',
                'contact_person' => $contact,
            ],
        ];

        $cargos = $this->extractCargos($lines);

        $attachment_filenames = [mb_strtolower($attachment_filename ?? '')];

        $data = compact(
            'customer',
            'loading_locations',
            'destination_locations',
            'attachment_filenames',
            'cargos',
            'order_reference',
            'transport_numbers',
            'freight_price',
            'freight_currency',
        );

        // return result of createOrder so caller (tinker) receives structured array
        return $this->createOrder($data);
    }

    public function extractLocations(array $lines)
    {
        $output = [];
        $index = 0;
        $count = count($lines);

        while ($index < $count) {
            $datetime = null;
            $location = null;

            // Start search from current index
            // Try to find datetime (e.g., '11.02.2025' or '11.02.2025 08:00-16:00') in the next few lines (max 4 lines)
            for ($i = $index; $i < $index + 4 && $i < $count; $i++) {
                if (preg_match('/^[0-9\.]+ ?([0-9:]+)?-?([0-9:]+)?$/', trim($lines[$i]))) {
                    $datetime = $lines[$i];
                    $index = $i + 1; // Start next search after datetime
                    break;
                }
            }

            for ($i = $index; $i < $index + 4 && $i < $count; $i++) {
                // Check for a complex address pattern (Company, Street, Postal City)
                if (preg_match('/^(.+?)\s*, +(.+?)\s*, +([A-Z]{1,2}-?[0-9]{4,}) +(.+)$/ui', $lines[$i])) {
                    $location = $lines[$i];
                    $index = $i + 1; // Start next search after location
                    break;
                }
            }

            // If we found both, process the location
            if ($location) { 
                $company_address = $this->parseCompanyAddress($location);
                if (!empty($company_address['company'])) {
                    $loc = [
                        'company_address' => $company_address,
                    ];
                    $time = $this->parseDateTime($datetime);
                    if (!empty($time)) {
                        $loc['time'] = $time;
                    }
                    $output[] = $loc;
                }
            }

            if (!$location) {
                $index++;
            }
        }
        return $output;
    }

    public function extractLocation(array $lines)
    {
        // guard against missing expected indices
        $datetime = $lines[2] ?? null;
        $location = $lines[4] ?? null;

        $company_address = $this->parseCompanyAddress($location);
        if (!$company_address || empty($company_address['company'])) {
            return null;
        }

        $time = $this->parseDateTime($datetime);

        $output = [
            'company_address' => $company_address,
        ];
        if (!empty($time)) {
            $output['time'] = $time;
        }

        return $output;
    }

    public function parseDateTime(?string $datetime)
    {
        if (empty($datetime)) {
            return [];
        }

        // preg_match('/^([0-9\.]+) ?([0-9:]+)?-?([0-9:]+)?$/', trim($datetime), $matches);
        preg_match('/^([0-9]+(?:\.[0-9]+)+) ?([0-9:]+)?-?([0-9:]+)?$/', trim($datetime), $matches);
        if (!$matches) {
            return [];
        }

        $date_start = $matches[1];
        if (!empty($matches[2])) {
            $date_start .= " " . $matches[2];
        }
        $date_start = Carbon::parse($date_start)->toIsoString();

        $date_end = $matches[1];
        if (!empty($matches[3])) {
            $date_end .= " " . $matches[3];
        }
        $date_end = Carbon::parse($date_end)->toIsoString();

        $output = [
            'datetime_from' => $date_start ?? null,
            'datetime_to' => $date_end ?? null,
        ];

        if ($output['datetime_from'] == $output['datetime_to']) {
            unset($output['datetime_to']);
        }

        return $output;
    }

    public function parseCompanyAddress(?string $location)
    {
        if (empty($location)) {
            return [
                'company' => null,
            ];
        }

        if (preg_match('/^(.+?)\s*, +(.+?)\s*, +([A-Z]{1,2}-?[0-9]{4,}) +(.+)$/ui', $location, $matches)) {
            $company = $matches[1];
            $street = $matches[2];
            $postal = $matches[3];
            $city = $matches[4];

            $country = preg_replace('/[^A-Z]/ui', '', $postal);
            $country = GeonamesCountry::getIso($country);

            $postal_code = preg_replace('/[^0-9]/ui', '', $postal);

            return [
                'company' => $company,
                'title' => $company,
                'street_address' => $street,
                'city' => $city,
                'postal_code' => $postal_code,
                'country' => $country,
            ];
        }

        $parts = array_map('trim', explode(',', $location));
        $company = $parts[0] ?? null;
        $street = $parts[1] ?? null;
        $city = $parts[count($parts) - 1] ?? null;

        // attempt to extract postal and country from last part
        $postal_code = null;
        $country = null;
        if (isset($parts[count($parts) - 2])) {
            $postal_part = $parts[count($parts) - 2];
            $postal_code = preg_replace('/[^0-9]/', '', $postal_part);
            $country = preg_replace('/[^A-Z]/ui', '', $postal_part);
            $country = GeonamesCountry::getIso($country);
        }

        return [
            'company' => $company,
            'title' => $company,
            'street_address' => $street,
            'city' => $city,
            'postal_code' => $postal_code,
            'country' => $country,
        ];
    }

    public function extractCargos(array $lines)
    {
        $load_li = array_find_key($lines, fn($l) => $l == "Load:");
        $title = $lines[$load_li + 1] ?? null;

        $amount_li = array_find_key($lines, fn($l) => $l == "Amount:");
        $package_count = isset($lines[$amount_li + 1]) && $lines[$amount_li + 1]
            ? uncomma($lines[$amount_li + 1])
            : null;

        $unit_li = array_find_key($lines, fn($l) => $l == "Unit:");
        $package_type = isset($lines[$unit_li + 1]) ? $this->mapPackageType($lines[$unit_li + 1]) : null;

        $weight_li = array_find_key($lines, fn($l) => $l == "Weight:");
        $weight = isset($lines[$weight_li + 1]) && $lines[$weight_li + 1]
            ? uncomma($lines[$weight_li + 1])
            : null;

        $ldm_li = array_find_key($lines, fn($l) => $l == "Loadingmeter:");
        $ldm = isset($lines[$ldm_li + 1]) && $lines[$ldm_li + 1]
            ? uncomma($lines[$ldm_li + 1])
            : null;

        $load_ref_li = array_find_key($lines, fn($l) => Str::startsWith($l, "Loading reference:"));
        $load_ref = $load_ref_li !== null
            ? explode(': ', $lines[$load_ref_li], 2)[1] ?? null
            : null;

        $unload_ref_li = array_find_key($lines, fn($l) => Str::startsWith($l, "Unloading reference:"));
        $unload_ref = $unload_ref_li !== null
            ? explode(': ', $lines[$unload_ref_li], 2)[1] ?? null
            : null;

        $number = join('; ', array_filter([$load_ref, $unload_ref]));

        return [
            [
                'title' => $title,
                'number' => $number,
                'package_count' => $package_count ?? 1,
                'package_type' => $package_type,
                'ldm' => $ldm,
                'weight' => $weight,
            ]
        ];
    }

    public function mapPackageType(string $type)
    {
        $package_type = static::PACKAGE_TYPE_MAP[$type] ?? "PALLET_OTHER";
        return trans("package_type.{$package_type}");
    }
}
