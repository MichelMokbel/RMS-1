<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class DailyDishOrderCustomerMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  Collection<int, Order>  $orders
     */
    public function __construct(
        public Collection $orders,
        public ?int $mealPlanMeals,
        public ?int $mealPlanRequestId
    ) {}

    public function envelope(): Envelope
    {
        $first = $this->orders->first();
        $name = $first?->customer_name_snapshot ?? 'Customer';

        return new Envelope(
            subject: "Your Daily Dish Order Confirmation - {$name}"
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.daily_dish_customer',
            with: [
                'orders' => $this->orders,
                'mealPlanMeals' => $this->mealPlanMeals,
                'mealPlanRequestId' => $this->mealPlanRequestId,
            ],
        );
    }
}


