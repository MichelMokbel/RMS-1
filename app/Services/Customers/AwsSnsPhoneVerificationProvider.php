<?php

namespace App\Services\Customers;

use App\Contracts\PhoneVerificationProvider;
use Aws\Sns\SnsClient;
use RuntimeException;

class AwsSnsPhoneVerificationProvider implements PhoneVerificationProvider
{
    public function sendVerificationCode(string $phoneE164, string $message, array $context = []): array
    {
        if (! class_exists(SnsClient::class)) {
            throw new RuntimeException('AWS SDK is not installed.');
        }

        $client = new SnsClient([
            'version' => '2010-03-31',
            'region' => (string) config('services.customer_sms.region'),
            'credentials' => [
                'key' => (string) config('services.ses.key'),
                'secret' => (string) config('services.ses.secret'),
            ],
        ]);

        $attributes = [
            'AWS.SNS.SMS.SMSType' => [
                'DataType' => 'String',
                'StringValue' => (string) config('services.customer_sms.sms_type', 'Transactional'),
            ],
        ];

        $senderId = trim((string) config('services.customer_sms.sender_id', ''));
        if ($senderId !== '') {
            $attributes['AWS.SNS.SMS.SenderID'] = [
                'DataType' => 'String',
                'StringValue' => $senderId,
            ];
        }

        $originationNumber = trim((string) config('services.customer_sms.origination_number', ''));
        if ($originationNumber !== '') {
            $attributes['AWS.MM.SMS.OriginationNumber'] = [
                'DataType' => 'String',
                'StringValue' => $originationNumber,
            ];
        }

        $result = $client->publish([
            'Message' => $message,
            'PhoneNumber' => $phoneE164,
            'MessageAttributes' => $attributes,
        ]);

        return [
            'provider' => 'aws_sns',
            'message_id' => $result->get('MessageId') ? (string) $result->get('MessageId') : null,
        ];
    }
}
