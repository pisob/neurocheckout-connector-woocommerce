<?php
declare(strict_types=1);

/** Explicit account preference only. Never infer consent from checkout or login. */
final class NCWooMarketingConsent
{
    private const VERSION = 'product-recommendations-v1';

    public static function register(): void
    {
        add_action('woocommerce_edit_account_form', [self::class, 'render']);
        add_action('woocommerce_save_account_details', [self::class, 'save']);
    }

    private static function key(int $blog): string { return '_ncwoo_marketing_consent_' . $blog; }
    private static function email($value): string
    {
        return is_string($value) && strlen($value) <= 254 && is_email($value)
            && !preg_match('/[\x00-\x20\x7f]/', $value) ? strtolower($value) : '';
    }
    private static function nonceAction(int $user, int $blog, string $email): string
    {
        return 'ncwoo-marketing:' . $blog . ':' . $user . ':' . hash('sha256', $email);
    }

    /** Read only, after signed-source authentication. Output goes to the local vault. */
    public static function snapshot(int $userId, int $blog): array
    {
        $unknown = ['customer' => (object) [], 'marketing_consent' => [
            'state' => 'unknown', 'source' => 'woocommerce_account_preference',
            'email' => '', 'scope' => (string) $blog, 'changed_at' => null]];
        if ($userId < 1 || $blog < 1 || $blog !== (int) get_current_blog_id()
            || (is_multisite() && !is_user_member_of_blog($userId, $blog))) { return $unknown; }
        $user = get_userdata($userId);
        $email = $user ? self::email($user->user_email) : '';
        if ($email === '') { return $unknown; }
        $result = $unknown;
        $result['customer'] = (object) ['email' => $email,
            'first_name' => mb_substr((string) $user->first_name, 0, 160),
            'last_name' => mb_substr((string) $user->last_name, 0, 160)];
        $result['marketing_consent']['email'] = $email;
        // Ambiguous metadata is not an opt-in. Never read a global/network key.
        $rows = get_user_meta($userId, self::key($blog), false);
        if (!is_array($rows) || count($rows) !== 1 || !is_array($rows[0])) { return $result; }
        $value = $rows[0];
        if (($value['version'] ?? null) !== self::VERSION || ($value['blog_id'] ?? null) !== $blog
            || ($value['customer_id'] ?? null) !== $userId || ($value['email'] ?? null) !== $email
            || !in_array($value['state'] ?? null, ['granted', 'denied'], true)
            || !is_int($value['changed_at'] ?? null) || $value['changed_at'] < 1
            || $value['changed_at'] > time() + 60) { return $result; }
        $result['marketing_consent']['state'] = $value['state'];
        $result['marketing_consent']['changed_at'] = gmdate('Y-m-d\TH:i:s\Z', $value['changed_at']);
        return $result;
    }

    public static function render(): void
    {
        $userId = (int) get_current_user_id(); $blog = (int) get_current_blog_id();
        $profile = self::snapshot($userId, $blog);
        $email = $profile['marketing_consent']['email'];
        if ($email === '') { return; }
        echo '<fieldset><legend>' . esc_html__('Product recommendations', 'neurocheckout-connector') . '</legend>';
        echo '<label><input type="checkbox" name="ncwoo_marketing_opt_in" value="1"'
            . ($profile['marketing_consent']['state'] === 'granted' ? ' checked' : '') . '> '
            . esc_html__('I would like to receive personalized product recommendations from this store by email. This is optional; I can unsubscribe at any time.', 'neurocheckout-connector')
            . '</label><p>' . esc_html__('This preference applies to your account email. After changing that address, please save your preference again.', 'neurocheckout-connector') . '</p>';
        wp_nonce_field(self::nonceAction($userId, $blog, $email), 'ncwoo_marketing_nonce', false);
        echo '</fieldset>';
    }

    public static function save($userId): void
    {
        // Woo invokes this after its account validation. Independently bind the
        // explicit choice to the current user, store and freshly saved email.
        if (!is_int($userId) || $userId < 1 || $userId !== (int) get_current_user_id()
            || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
            || !is_string($_POST['ncwoo_marketing_nonce'] ?? null)
            || (isset($_POST['ncwoo_marketing_opt_in']) && $_POST['ncwoo_marketing_opt_in'] !== '1')) { return; }
        $blog = (int) get_current_blog_id();
        $profile = self::snapshot($userId, $blog);
        $email = $profile['marketing_consent']['email'];
        if ($email === '' || !wp_verify_nonce(wp_unslash($_POST['ncwoo_marketing_nonce']),
                self::nonceAction($userId, $blog, $email))) { return; }
        $state = isset($_POST['ncwoo_marketing_opt_in']) ? 'granted' : 'denied';
        if ($profile['marketing_consent']['state'] === $state) { return; }
        update_user_meta($userId, self::key($blog), ['version' => self::VERSION,
            'blog_id' => $blog, 'customer_id' => $userId, 'email' => $email,
            'state' => $state, 'changed_at' => time()]);
        if (self::snapshot($userId, $blog)['marketing_consent']['state'] !== $state) {
            wc_add_notice(__('Your recommendation preference could not be saved. Please try again.', 'neurocheckout-connector'), 'error');
        }
    }
}
