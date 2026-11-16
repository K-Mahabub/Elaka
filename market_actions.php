<?php
/**
 * Marketplace listing actions: mark available / reserved / sold, renew, delete.
 * Comments, reactions and reports reuse actions.php.
 */
require __DIR__ . '/includes/bootstrap.php';
$me = require_login();

if (!is_post()) {
    redirect('marketplace.php');
}
verify_csrf();

$listing = get_listing((int)input('post_id'));
if (!$listing) {
    flash('error', 'That listing no longer exists.');
    redirect('marketplace.php');
}
$postId = (int)$listing['id'];
$back = market_url($postId);
$isOwner = (int)$listing['user_id'] === (int)$me['id'];
$isMod = has_role('moderator', $me);

if (!$isOwner && !$isMod) {
    flash('error', 'Only the seller can manage this listing.');
    redirect($back);
}

switch (input('action')) {
    case 'set_status':
        $status = input('status');
        if (!isset(market_statuses()[$status]) || $listing['status'] !== 'active') {
            flash('error', 'This listing cannot be changed right now.');
            break;
        }
        if ($status !== $listing['sale_status']) {
            market_set_status($listing, $status);
            if (!$isOwner) {
                notify((int)$listing['user_id'], $postId, 'market_status', "🛒 A moderator marked your listing “{$listing['title']}” as " . market_statuses()[$status]['label'] . '.');
            }
            flash('success', match ($status) {
                'sold'     => '🎉 Marked as sold. It will be cleared from the marketplace in ' . market_cfg('sold_days') . ' days.',
                'reserved' => 'Marked as reserved. Buyers can still see it but know it is spoken for.',
                default    => 'Marked as available again.',
            });
        }
        break;

    case 'renew':
        if (!$isOwner || $listing['status'] !== 'active') {
            break;
        }
        if ($listing['sale_status'] === 'sold') {
            market_set_status($listing, 'available');
        }
        market_renew($listing);
        flash('success', 'Listing renewed for another ' . market_cfg('expiry_days') . ' days.');
        break;

    case 'delete':
        if ($isOwner) {
            delete_post_completely($listing);
            flash('success', 'Your listing was deleted.');
            redirect('marketplace.php?mine=1');
        }
        q("UPDATE posts SET status = 'removed' WHERE id = ?", [$postId]);
        q("UPDATE reports SET status = 'resolved', resolved_by = ? WHERE post_id = ? AND status = 'open'", [$me['id'], $postId]);
        notify((int)$listing['user_id'], $postId, 'removed', "🚫 Your listing “{$listing['title']}” was removed by a moderator.");
        flash('success', 'Listing removed.');
        redirect('marketplace.php');

    default:
        flash('error', 'Unknown action.');
}

redirect($back);
