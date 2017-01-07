<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SpotIM_Settings_Fields {
    public function __construct( $options ) {
        $this->options = $options;
    }

    public function register_settings() {
        register_setting(
            $this->options->option_group,
            $this->options->slug,
            array( $this->options, 'validate' )
        );
    }

    public function general_settings_section_header() {
        echo '<p>' . esc_html__( 'These are some basic settings for Spot.IM.', 'spotim-comments' ) . '</p>';
    }

    public function import_settings_section_header() {
        echo '<p>' . esc_html__( 'Import your comments from Spot.IM to WordPress.', 'spotim-comments' ) . '</p>';
    }

    public function register_general_section() {
        add_settings_section(
            'general_settings_section',
            esc_html__( 'Commenting Options', 'spotim-comments' ),
            array( $this, 'general_settings_section_header' ),
            $this->options->slug
        );

        add_settings_field(
            'enable_comments_replacement',
            esc_html__( 'Enable Spot.IM comments', 'spotim-comments' ),
            array( 'SpotIM_Form_Helper', 'yes_no_fields' ),
            $this->options->slug,
            'general_settings_section',
            array(
                'id' => 'enable_comments_replacement',
                'page' => $this->options->slug,
                'value' => $this->options->get( 'enable_comments_replacement' )
            )
        );

        add_settings_field(
            'enable_comments_on_page',
            esc_html__( 'Enable Spot.IM on pages', 'spotim-comments' ),
            array( 'SpotIM_Form_Helper', 'yes_no_fields' ),
            $this->options->slug,
            'general_settings_section',
            array(
                'id' => 'enable_comments_on_page',
                'page' => $this->options->slug,
                'value' => $this->options->get( 'enable_comments_on_page' )
            )
        );

        $translated_spot_id_description = sprintf(
		    __('Find your Spot ID at the Spot.IM\'s <a href="%s" target="_blank">Admin Dashboard</a> under Integrations section.' , 'spotim-comments'),
            'https://admin.spot.im/login'
        ) . '<br />' . sprintf(
			__('Don\'t have an account? <a href="%s" target="_blank">Create</a> one for free!' , 'spotim-comments'),
            'https://admin.spot.im/login'
        );

        add_settings_field(
            'spot_id',
            esc_html__( 'Your Spot ID', 'spotim-comments' ),
            array( 'SpotIM_Form_Helper', 'text_field' ),
            $this->options->slug,
            'general_settings_section',
            array(
                'id' => 'spot_id',
                'page' => $this->options->slug,
                'description' => $translated_spot_id_description,
                'value' => $this->options->get( 'spot_id' )
            )
        );
    }

    public function register_import_section() {
        add_settings_section(
            'import_settings_section',
            esc_html__( 'Import Options', 'spotim-comments' ),
            array( $this, 'import_settings_section_header' ),
            $this->options->slug
        );

        add_settings_field(
            'import_token',
            esc_html__( 'Your Token', 'spotim-comments' ),
            array( 'SpotIM_Form_Helper', 'text_field' ),
            $this->options->slug,
            'import_settings_section',
            array(
                'id' => 'import_token',
                'page' => $this->options->slug,
                'description' => 'Don\'t have a token? please send us an email to support@spot.im and get one.',
                'value' => $this->options->get( 'import_token' )
            )
        );

        add_settings_field(
            'posts_per_request',
            esc_html__( 'Posts Per Request', 'spotim-comments' ),
            array( 'SpotIM_Form_Helper', 'text_field' ),
            $this->options->slug,
            'import_settings_section',
            array(
                'id' => 'posts_per_request',
                'page' => $this->options->slug,
                'description' => 'Amount of posts to retrieve in each request, depending on your server\'s strength.',
                'value' => $this->options->get( 'posts_per_request' )
            )
        );

        add_settings_field(
            'import_button',
            '',
            array( 'SpotIM_Form_Helper', 'import_button' ),
            $this->options->slug,
            'import_settings_section',
            array(
                'import_button' => array(
                    'id' => 'import_button',
                    'text' => esc_html__( 'Import', 'spotim-comments' )
                ),
                'cancel_import_link' => array(
                    'id' => 'cancel_import_link',
                    'text' => esc_html__( 'Cancel', 'spotim-comments' )
                )
            )
        );
    }
}
