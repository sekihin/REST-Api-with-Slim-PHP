<?php

declare(strict_types=1);

namespace App\Services;

use DBUIS;
use DateTimeImmutable;
use Exception;

readonly class ContractMailDataService
{
    public function __construct(
        private DBUIS $db,
        private string $mailFooter
    ) {}

    /**
     * @return array<string, mixed>
     * @throws Exception
     */
    public function getShippingScheduleData(string $licenseKey): array
    {
        // 1. Fetch Contract, User, and Product in a single, high-performance query
        $sql = "
            SELECT 
                c.*,
                u.COMPANY_NM,
                u.CONTACT_PERSON_NM,
                p.PRODUCT_NM
            FROM f_contract c
            LEFT JOIN f_user u 
                ON c.CUSTOMER_CD = u.USER_CD 
                AND c.CUSTOMER_OFFICE = u.USER_OFFICE 
                AND c.CUSTOMER_PERSON_CD = u.USER_PERSON_CD
            LEFT JOIN m_product p 
                ON c.PRODUCT_CD = p.PRODUCT_CD
            WHERE c.LICENSE_KEY = ?
        ";
        
        $this->db->query($sql, $licenseKey);
        $contract = $this->db->fetchArray();

        if (empty($contract)) {
            throw new Exception("Contract not found for license key: {$licenseKey}");
        }

        // 2. Process dates and Japanese weekdays
        $shipmentDate = $this->formatJapaneseDate($contract['SHIPMENT_PLAN_AT']);
        $arrivalDate = $this->formatJapaneseDate($contract['ARRIVAL_PLAN_AT01']);

        // 3. Map to Twig Variables
        return [
            // Master/Joined Data
            'company_nm'               => $contract['COMPANY_NM'] ?? '',
            'contact_person_nm'        => $contract['CONTACT_PERSON_NM'] ?? '',
            'product_nm'               => $contract['PRODUCT_NM'] ?? '',
            
            // Map License Type to Name (You may need another JOIN if this is a master table, 
            // but for now we output the code or a default)
            'license_type01_nm'        => $contract['LICENSE_TYPE01'] ?? '新規', 

            // Direct Contract Data
            'flag_backup_discrim'      => (int) ($contract['BACKUP_DISCRIM'] === '1'),
            'version'                  => $contract['VERSION'] ?? '',
            'license_period_range'     => $this->formatPeriod($contract['LICENSE_PERIOD_SELF'], $contract['LICENSE_PERIOD_ULTIME']),
            'quantity'                 => $contract['QUANTITY'] ?? 0,
            
            // License Info Array
            'license_info'             => [
                'serial_no' => $contract['LICENSE_KEY'] ?? ''
            ],
            
            // Remarks
            'flag_has_product_remarks' => !empty($contract['REMARKS_SHIPPING']) ? 1 : 0,
            'product_remarks'          => $contract['REMARKS_SHIPPING'] ?? '',

            // Shipment & Arrival Dates
            'flag_has_shipment_at'     => $shipmentDate !== null,
            'shipment_at'              => $shipmentDate['date'] ?? '',
            'shipment_at_w'            => $shipmentDate['weekday'] ?? '',

            'flag_has_plan_at'         => $arrivalDate !== null,
            'plan_at'                  => $arrivalDate['date'] ?? '',
            'plan_at_w'                => $arrivalDate['weekday'] ?? '',

            // Receiver Info
            'receiver_company'         => $contract['RECEIVER_COMPANY'] ?? '',
            'receiver_zip'             => $contract['RECEIVER_ZIP'] ?? '',
            'receiver_add01'           => $contract['RECEIVER_ADD01'] ?? '',
            'receiver_add02'           => $contract['RECEIVER_ADD02'] ?? '',
            'receiver_dp'              => $contract['RECEIVER_DP'] ?? '',
            'receiver_person_nm'       => $contract['RECEIVER_PERSON_NM'] ?? '',
            'receiver_mail'            => $contract['RECEIVER_MAIL'] ?? '',
            'receiver_tel'             => $contract['RECEIVER_TEL'] ?? '',

            // Order Info
            'partner_order_number'     => $contract['PARTNER_ORDER_NUMBER'] ?? '',
            'order_no'                 => $contract['ORDER_NO'] ?? '',
            'updated_at'               => $contract['UPDATED_AT'] ? (new DateTimeImmutable($contract['UPDATED_AT']))->format('Y-m-d H:i:s') : '',
            
            // Footer injected from config
            'mail_footer'              => $this->mailFooter,
        ];
    }

    /**
     * @return array{date: string, weekday: string}|null
     */
    private function formatJapaneseDate(?string $dateString): ?array
    {
        if (empty($dateString)) {
            return null;
        }

        $date = new DateTimeImmutable($dateString);
        $dayOfWeek = (int) $date->format('w');

        $japaneseWeekday = match ($dayOfWeek) {
            0 => '日',
            1 => '月',
            2 => '火',
            3 => '水',
            4 => '木',
            5 => '金',
            6 => '土',
        };

        return [
            'date'    => $date->format('Y/m/d'),
            'weekday' => $japaneseWeekday,
        ];
    }

    private function formatPeriod(?string $start, ?string $end): string
    {
        if (!$start && !$end) return '';
        $startFmt = $start ? (new DateTimeImmutable($start))->format('Y/m/d') : '';
        $endFmt = $end ? (new DateTimeImmutable($end))->format('Y/m/d') : '';
        return "{$startFmt} ～ {$endFmt}";
    }
}