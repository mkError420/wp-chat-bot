<?php
/**
 * Plugin Name: ChatBot
 * Description: Adds a website-aware chatbot that answers visitor questions using your WordPress content.
 * Version: 1.0
 * Author: GitHub Copilot
 * Text Domain: chatbot
 */

if (! defined('ABSPATH')) {
    exit;
}

class ChatBot_Plugin {
    public function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_shortcode('chatbot_widget', [$this, 'render_chatbot_shortcode']);
        add_action('wp_footer', [$this, 'maybe_render_footer_chatbot']);
    }

    public function enqueue_assets() {
        if (is_admin()) {
            return;
        }

        $base_url = plugin_dir_url(__FILE__);
        wp_enqueue_style('chatbot-style', $base_url . 'assets/css/chatbot.css', [], '1.0');
        wp_enqueue_script('chatbot-script', $base_url . 'assets/js/chatbot.js', [], '1.0', true);

        wp_localize_script('chatbot-script', 'chatbotSettings', [
            'restUrl' => esc_url_raw(rest_url('chatbot/v1/respond')),
            'nonce' => wp_create_nonce('wp_rest'),
            'siteName' => get_bloginfo('name'),
        ]);
    }

    public function register_rest_routes() {
        register_rest_route('chatbot/v1', '/respond', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_rest_request'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function render_chatbot_shortcode($atts) {
        return $this->get_chatbot_html();
    }

    public function maybe_render_footer_chatbot() {
        if (is_admin()) {
            return;
        }

        $post = get_post();
        if ($post && has_shortcode($post->post_content, 'chatbot_widget')) {
            return;
        }

        echo $this->get_chatbot_html();
    }

    public function get_chatbot_html() {
        ob_start();
        ?>
        <div id="chatbot-widget" class="chatbot-widget">
            <button id="chatbot-toggle" class="chatbot-toggle" aria-expanded="false">Chat with us</button>
            <div class="chatbot-panel" aria-hidden="true">
                <div class="chatbot-header">
                    <strong>Website ChatBot</strong>
                    <button id="chatbot-close" class="chatbot-close" aria-label="Close chat">×</button>
                </div>
                <div class="chatbot-messages" id="chatbot-messages">
                    <div class="chatbot-message bot">
                        Hi! Ask me anything about this website and I will answer from the site content.
                    </div>
                </div>
                <form id="chatbot-form" class="chatbot-form">
                    <input id="chatbot-input" class="chatbot-input" type="text" autocomplete="off" placeholder="Type your question..." aria-label="Ask a question" required />
                    <button type="submit" class="chatbot-submit">Send</button>
                </form>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function handle_rest_request(WP_REST_Request $request) {
        $message = trim($request->get_param('message'));

        if (empty($message)) {
            return new WP_Error('empty_message', 'Please send a question for the chatbot to answer.', ['status' => 400]);
        }

        $documents = $this->get_searchable_documents();
        $best = $this->find_best_answer($message, $documents);

        if (! $best) {
            return rest_ensure_response([
                'answer' => 'I reviewed the site content but could not find a direct answer. Please try asking in a different way or browse the website for more information.',
                'source' => home_url('/'),
            ]);
        }

        return rest_ensure_response([
            'answer' => $best['answer'],
            'source' => $best['link'],
            'title' => $best['title'],
        ]);
    }

    private function get_searchable_documents() {
        $posts = get_posts([
            'post_type' => ['post', 'page'],
            'post_status' => 'publish',
            'posts_per_page' => 100,
            'suppress_filters' => true,
        ]);

        $documents = [];
        foreach ($posts as $post) {
            $content = $post->post_content;
            $documents[] = [
                'title' => get_the_title($post),
                'content' => wp_strip_all_tags($content),
                'link' => get_permalink($post),
                'excerpt' => $this->extract_snippet($content, $post->post_title),
            ];
        }

        return $documents;
    }

    private function find_best_answer($question, $documents) {
        $clean_question = $this->normalize_text($question);
        $keywords = $this->build_keywords($clean_question);
        $best_score = 0;
        $best_document = null;

        foreach ($documents as $document) {
            $haystack = $this->normalize_text($document['title'] . ' ' . $document['content']);
            $score = 0;

            foreach ($keywords as $keyword) {
                if (strpos($haystack, $keyword) !== false) {
                    $score += 20;
                    $score += substr_count($haystack, $keyword) * 3;
                }
            }

            similar_text($clean_question, $this->normalize_text($document['title']), $percent);
            $score += $percent * 0.6;

            if ($score > $best_score) {
                $best_score = $score;
                $best_document = $document;
            }
        }

        if (! $best_document || $best_score < 20) {
            return null;
        }

        $snippet = $this->extract_snippet($best_document['content'], implode(' ', $keywords));
        $answer_text = sprintf(
            'I found information in "%s". %s',
            $best_document['title'],
            $snippet
        );

        return [
            'answer' => $answer_text,
            'title' => $best_document['title'],
            'link' => $best_document['link'],
        ];
    }

    private function normalize_text($text) {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9\s]/', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    private function build_keywords($text) {
        $words = array_filter(explode(' ', $text), function ($word) {
            return strlen($word) > 2;
        });
        return array_unique($words);
    }

    private function extract_snippet($content, $keywords) {
        $content = wp_strip_all_tags($content);
        $content = preg_replace('/\s+/', ' ', trim($content));

        $text = $content;
        $snippet = '';

        if (is_string($keywords) && ! empty($keywords)) {
            $keywords = $this->build_keywords($this->normalize_text($keywords));
        }

        foreach ($keywords as $keyword) {
            $pos = stripos($text, $keyword);
            if ($pos !== false) {
                $start = max(0, $pos - 120);
                $snippet = trim(substr($text, $start, 280));
                if ($start > 0) {
                    $snippet = '...' . $snippet;
                }
                if (strlen($text) > $pos + 280) {
                    $snippet = $snippet . '...';
                }
                break;
            }
        }

        if (empty($snippet)) {
            $snippet = substr($text, 0, 280);
            if (strlen($text) > 280) {
                $snippet = trim($snippet) . '...';
            }
        }

        return $snippet;
    }
}

new ChatBot_Plugin();
