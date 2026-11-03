<?php

namespace App\Microsoft;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Outlook categories in the user's own mailbox (E1, R10), always with the user's token.
 */
class OutlookCategories
{
    public const CLICKUP = 'ClickUp';

    public const CLICKUP_DONE = 'ClickUp ✓';

    public const SUPPORT_DONE = 'Support ✓';

    /**
     * Outlook colour presets: blue, green, orange.
     */
    private const MASTER = [
        self::CLICKUP => 'preset7',
        self::CLICKUP_DONE => 'preset4',
        self::SUPPORT_DONE => 'preset1',
    ];

    public function __construct(private UserTokens $tokens) {}

    /**
     * Creates the three categories in the user's master list if they are missing. Existing
     * ones (also with another colour) are left untouched. Outlook compares names without case.
     *
     * @return list<string> names that were created
     */
    public function ensureMasterCategories(User $user): array
    {
        $graph = $this->tokens->graphFor($user);
        $existing = collect($graph->get('me/outlook/masterCategories')['value'] ?? [])
            ->map(fn (array $category): string => Str::lower((string) ($category['displayName'] ?? '')));

        $created = [];

        foreach (self::MASTER as $name => $color) {
            if ($existing->contains(Str::lower($name))) {
                continue;
            }

            try {
                $graph->post('me/outlook/masterCategories', ['displayName' => $name, 'color' => $color]);
                $created[] = $name;
            } catch (GraphException $e) {
                // Created in the meantime (second job, user in Outlook): fine.
                if ($e->status !== 409) {
                    throw $e;
                }
            }
        }

        return $created;
    }

    /**
     * Replaces one category on a mail of the user and keeps all others. $to null only removes.
     * Outlook treats category names without case, so "Clickup" counts as "ClickUp".
     *
     * Returns whether the mail now carries no variant of $from and, if given, $to. The
     * categories Graph returns after the PATCH are checked, not the ones we sent. A mail
     * deleted in Outlook counts as done: its category cannot get in the way any more.
     */
    public function replace(User $user, string $graphMessageId, string $from, ?string $to): bool
    {
        $graph = $this->tokens->graphFor($user);
        $path = 'me/messages/'.rawurlencode($graphMessageId);

        try {
            $current = $this->names($graph->get($path, ['$select' => 'categories'])['categories'] ?? []);
            $categories = array_values(array_filter($current, fn (string $name): bool => ! self::same($name, $from)));

            if ($to !== null && ! self::contains($categories, $to)) {
                $categories[] = $to;
            }

            if ($categories === $current) {
                return true;
            }

            $after = $graph->patch($path, ['categories' => $categories]);
            $result = array_key_exists('categories', $after) ? $this->names($after['categories']) : $categories;

            return ! self::contains($result, $from) && ($to === null || self::contains($result, $to));
        } catch (GraphException $e) {
            if (! $e->isNotFound()) {
                throw $e;
            }

            Log::info('Outlook mail no longer exists, category not updated.', ['user_id' => $user->id]);

            return true;
        }
    }

    public static function same(string $a, string $b): bool
    {
        return Str::lower($a) === Str::lower($b);
    }

    /**
     * @param  list<string>  $categories
     */
    public static function contains(array $categories, string $name): bool
    {
        foreach ($categories as $category) {
            if (self::same($category, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function names(mixed $categories): array
    {
        return is_array($categories) ? array_values(array_filter($categories, 'is_string')) : [];
    }
}
