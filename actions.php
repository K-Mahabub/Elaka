<?php
/**
 * Handles all post interactions: react, comment, vote, report, verify, delete.
 */
require __DIR__ . '/includes/bootstrap.php';
$me = require_login();

if (!is_post()) {
    redirect('index.php');
}
verify_csrf();

$action = input('action');
$isMod = has_role('moderator', $me);

// Resolve the target post (directly, or via a comment).
$comment = null;
if (in_array($action, ['delete_comment', 'report'], true) && (int)input('comment_id')) {
    $comment = q('SELECT * FROM comments WHERE id = ?', [(int)input('comment_id')])->fetch() ?: null;
    if (!$comment) {
        flash('error', 'Comment not found.');
        redirect('index.php');
    }
    $postId = (int)$comment['post_id'];
} else {
    $postId = (int)input('post_id');
}

$post = get_post($postId);
if (!$post) {
    flash('error', 'That post no longer exists.');
    redirect('index.php');
}
$back = 'post.php?id=' . $postId;
if (input('return') !== '') {
    // Like buttons in the feed, and comments on marketplace listings, return to the page they came from.
    $back = safe_return(input('return'), $back);
}
$isAuthor = (int)$post['user_id'] === (int)$me['id'];

if (!can_access_post($post)) {
    flash('error', 'You can only interact with posts in your area.');
    redirect($back);
}

$interactive = $post['status'] === 'active' && !post_is_expired($post);

switch ($action) {
    case 'react':
        $type = input('type');
        if (!$interactive || !isset(reaction_types()[$type])) {
            break;
        }
        if ($post['my_reaction'] === $type) {
            q('DELETE FROM reactions WHERE post_id = ? AND user_id = ?', [$postId, $me['id']]);
        } else {
            q(
                'INSERT INTO reactions (post_id, user_id, type) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE type = VALUES(type), created_at = NOW()',
                [$postId, $me['id'], $type]
            );
        }
        break;

    case 'comment':
        $body = input('body');
        if (!$interactive) {
            flash('error', 'This post is closed for comments.');
        } elseif (honeypot_tripped()) {
            flash('error', 'Comment rejected.');
        } elseif (mb_strlen($body) < 1 || mb_strlen($body) > 2000) {
            flash('error', 'Comments must be 1–2000 characters.');
        } elseif (count_links($body) > config('max_links_per_post')) {
            flash('error', 'Too many links in this comment.');
        } elseif (!$isMod && rate_limited('comments', (int)$me['id'], (int)config('rate_limit_comments'))) {
            flash('error', 'You are commenting too quickly. Please slow down.');
        } elseif (q('SELECT 1 FROM comments WHERE post_id = ? AND user_id = ? AND body = ? AND created_at > NOW() - INTERVAL 10 MINUTE', [$postId, $me['id'], $body])->fetchColumn()) {
            flash('error', 'You already posted that comment.');
        } else {
            q('INSERT INTO comments (post_id, user_id, body, is_anonymous) VALUES (?, ?, ?, ?)', [$postId, $me['id'], $body, input('is_anonymous') ? 1 : 0]);
            $cid = (int)db()->lastInsertId();
            if (!$isAuthor) {
                $who = input('is_anonymous') ? 'Someone' : $me['name'];
                notify((int)$post['user_id'], $postId, 'comment', "💬 $who commented on “{$post['title']}”");
            }
            $back .= '#c' . $cid;
        }
        break;

    case 'delete_comment':
        if ($comment && ((int)$comment['user_id'] === (int)$me['id'] || $isMod)) {
            q("UPDATE comments SET status = 'removed' WHERE id = ?", [$comment['id']]);
            q("UPDATE reports SET status = 'resolved', resolved_by = ? WHERE comment_id = ? AND status = 'open'", [$me['id'], $comment['id']]);
            flash('success', 'Comment deleted.');
            $back .= '#comments';
        }
        break;

    case 'vote':
        $optionId = (int)input('option_id');
        $valid = q('SELECT 1 FROM poll_options WHERE id = ? AND post_id = ?', [$optionId, $postId])->fetchColumn();
        if ($post['category'] !== 'poll' || !$valid || !$interactive) {
            flash('error', 'This vote could not be recorded.');
            break;
        }
        q(
            'INSERT INTO poll_votes (post_id, user_id, option_id) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE option_id = VALUES(option_id), created_at = NOW()',
            [$postId, $me['id'], $optionId]
        );
        flash('success', 'Your vote was recorded.');
        break;

    case 'report':
        $reason = input('reason');
        if (!isset(report_reasons()[$reason])) {
            $reason = 'other';
        }
        $details = mb_substr(input('details'), 0, 500);
        $commentId = $comment ? (int)$comment['id'] : null;
        $dup = $commentId
            ? q('SELECT 1 FROM reports WHERE user_id = ? AND comment_id = ?', [$me['id'], $commentId])->fetchColumn()
            : q('SELECT 1 FROM reports WHERE user_id = ? AND post_id = ? AND comment_id IS NULL', [$me['id'], $postId])->fetchColumn();
        if ($dup) {
            flash('info', 'You have already reported this. Moderators will review it.');
            break;
        }
        if (rate_limited('reports', (int)$me['id'], 10)) {
            flash('error', 'Too many reports in a short time. Please wait.');
            break;
        }
        q(
            'INSERT INTO reports (user_id, post_id, comment_id, reason, details) VALUES (?, ?, ?, ?, ?)',
            [$me['id'], $postId, $commentId, $reason, $details ?: null]
        );
        if (!$commentId && maybe_auto_hide($postId)) {
            flash('success', 'Thanks. This post has been hidden pending moderator review.');
            redirect('index.php');
        }
        flash('success', 'Thanks for reporting. A moderator will review it.');
        break;

    case 'verify':
        if (!has_role('trusted', $me) || $isAuthor) {
            flash('error', 'Only trusted members and moderators can verify posts by others.');
            break;
        }
        if (!$post['is_verified']) {
            q('UPDATE posts SET is_verified = 1, verified_by = ?, verified_at = NOW() WHERE id = ?', [$me['id'], $postId]);
            notify((int)$post['user_id'], $postId, 'verified', "✔ Your post “{$post['title']}” was verified as accurate local information.");
            flash('success', 'Post marked as verified.');
        }
        break;

    case 'unverify':
        if ($isMod) {
            q('UPDATE posts SET is_verified = 0, verified_by = NULL, verified_at = NULL WHERE id = ?', [$postId]);
            flash('success', 'Verification removed.');
        }
        break;

    case 'delete_post':
        if ($isAuthor) {
            delete_post_completely($post);
            flash('success', 'Your post was deleted.');
            redirect('index.php');
        }
        if ($isMod) {
            q("UPDATE posts SET status = 'removed' WHERE id = ?", [$postId]);
            q("UPDATE reports SET status = 'resolved', resolved_by = ? WHERE post_id = ? AND status = 'open'", [$me['id'], $postId]);
            notify((int)$post['user_id'], $postId, 'removed', "🚫 Your post “{$post['title']}” was removed by a moderator.");
            flash('success', 'Post removed.');
            redirect('index.php');
        }
        break;

    default:
        flash('error', 'Unknown action.');
}

redirect($back);
