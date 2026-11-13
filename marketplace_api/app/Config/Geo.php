<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Offline Bangladesh area list and city centres (server-side copy of public/assets/marketplace/omnipos-geo.js).
 * Used to resolve a picked area to coordinates, to name the nearest area for a browser location,
 * and to find an area in an address. No map service is called.
 */
class Geo extends BaseConfig
{
    /** @var list<array{0: string, 1: string, 2: float, 3: float}> [area, city, lat, lng] — Dhaka areas first, then major towns */
    public array $areas = [
        ['Gulshan', 'Dhaka', 23.7925, 90.4078], ['Banani', 'Dhaka', 23.7937, 90.4066], ['Baridhara', 'Dhaka', 23.8003, 90.4209], ['Bashundhara R/A', 'Dhaka', 23.8193, 90.4526],
        ['Uttara', 'Dhaka', 23.8759, 90.3795], ['Mirpur', 'Dhaka', 23.8223, 90.3654], ['Pallabi', 'Dhaka', 23.8256, 90.3654], ['Mohammadpur', 'Dhaka', 23.7662, 90.3589],
        ['Dhanmondi', 'Dhaka', 23.7461, 90.3742], ['Panthapath', 'Dhaka', 23.7516, 90.3862], ['Farmgate', 'Dhaka', 23.7561, 90.3872], ['Karwan Bazar', 'Dhaka', 23.7515, 90.3936],
        ['Tejgaon', 'Dhaka', 23.7639, 90.3925], ['Mohakhali', 'Dhaka', 23.7779, 90.4048], ['Badda', 'Dhaka', 23.7806, 90.4265], ['Rampura', 'Dhaka', 23.7612, 90.4214],
        ['Malibagh', 'Dhaka', 23.7480, 90.4120], ['Moghbazar', 'Dhaka', 23.7489, 90.4045], ['Shantinagar', 'Dhaka', 23.7398, 90.4140], ['Motijheel', 'Dhaka', 23.7330, 90.4172],
        ['Paltan', 'Dhaka', 23.7363, 90.4125], ['Khilgaon', 'Dhaka', 23.7516, 90.4265], ['Jatrabari', 'Dhaka', 23.7104, 90.4349], ['Old Dhaka', 'Dhaka', 23.7104, 90.4074],
        ['Chawkbazar', 'Dhaka', 23.7179, 90.3960], ['Lalbagh', 'Dhaka', 23.7193, 90.3880], ['Azimpur', 'Dhaka', 23.7276, 90.3858], ['New Market', 'Dhaka', 23.7330, 90.3846],
        ['Shahbagh', 'Dhaka', 23.7383, 90.3958], ['Kakrail', 'Dhaka', 23.7381, 90.4074], ['Wari', 'Dhaka', 23.7167, 90.4207], ['Moulvibazar', 'Dhaka', 23.7158, 90.4004],
        ['Baitul Mukarram', 'Dhaka', 23.7296, 90.4130], ['Banglabazar', 'Dhaka', 23.7076, 90.4115], ['Sutrapur', 'Dhaka', 23.7090, 90.4170], ['Gulistan', 'Dhaka', 23.7237, 90.4114],
        ['Khilkhet', 'Dhaka', 23.8290, 90.4235], ['Banasree', 'Dhaka', 23.7637, 90.4333], ['Aftabnagar', 'Dhaka', 23.7698, 90.4446], ['Shyamoli', 'Dhaka', 23.7745, 90.3657],
        ['Agargaon', 'Dhaka', 23.7778, 90.3794], ['Kallyanpur', 'Dhaka', 23.7814, 90.3606], ['Savar', 'Dhaka', 23.8583, 90.2667], ['Keraniganj', 'Dhaka', 23.6983, 90.3456],
        ['Narayanganj', 'Narayanganj', 23.6238, 90.5000], ['Gazipur', 'Gazipur', 23.9999, 90.4203], ['Tongi', 'Gazipur', 23.8915, 90.4023],
        ['Agrabad', 'Chattogram', 22.3263, 91.8123], ['GEC Circle', 'Chattogram', 22.3590, 91.8215], ['Nasirabad', 'Chattogram', 22.3666, 91.8239], ['Halishahar', 'Chattogram', 22.3285, 91.7810],
        ['Chawkbazar (Ctg)', 'Chattogram', 22.3533, 91.8370], ['Khulshi', 'Chattogram', 22.3606, 91.8090], ['Panchlaish', 'Chattogram', 22.3685, 91.8344],
        ['Sylhet Sadar', 'Sylhet', 24.8949, 91.8687], ['Zindabazar', 'Sylhet', 24.8966, 91.8713], ['Rajshahi Sadar', 'Rajshahi', 24.3745, 88.6042], ['Khulna Sadar', 'Khulna', 22.8456, 89.5403],
        ['Barishal Sadar', 'Barishal', 22.7010, 90.3535], ['Rangpur Sadar', 'Rangpur', 25.7439, 89.2752], ['Mymensingh Sadar', 'Mymensingh', 24.7471, 90.4203], ['Cumilla Sadar', 'Cumilla', 23.4607, 91.1809],
        ["Cox's Bazar", "Cox's Bazar", 21.4272, 92.0058], ['Bogura Sadar', 'Bogura', 24.8465, 89.3773], ['Jashore Sadar', 'Jashore', 23.1664, 89.2081],
    ];

    /** @var array<string, array{0: float, 1: float}> city centres, used when an address names only the city */
    public array $cities = [
        'Dhaka' => [23.8103, 90.4125], 'Chattogram' => [22.3569, 91.7832], 'Sylhet' => [24.8949, 91.8687], 'Rajshahi' => [24.3745, 88.6042],
        'Khulna' => [22.8456, 89.5403], 'Barishal' => [22.7010, 90.3535], 'Rangpur' => [25.7439, 89.2752], 'Mymensingh' => [24.7471, 90.4203],
        'Cumilla' => [23.4607, 91.1809], 'Gazipur' => [23.9999, 90.4203], 'Narayanganj' => [23.6238, 90.5000],
    ];

    /** Radius chips (km); 0 = any distance. */
    public array $radiusChips = [2, 5, 10, 25, 0];

    public int $defaultRadiusKm = 10;
}
