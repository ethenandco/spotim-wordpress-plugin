<?php

// define( 'JSONSTUB_EXPORT_URL', 'http://jsonstub.com/export/wordpress/anonymous/reply' );

class SpotIM_Import {
    public function __construct( $options ) {
        $this->options = $options;
    }

    public function start( $spot_id, $import_token ) {
        $post_ids = $this->get_post_ids();
        $streams = array();

        $this->options->update( 'spot_id', $spot_id );
        $this->options->update( 'import_token', $import_token );

        // import comments data from Spot.IM
        $streams = $this->fetch_comments( $post_ids );

        // sync comments data with wordpress comments
        $this->merge_comments( $streams );

    }

    private function fetch_comments( $post_ids ) {
        if ( ! empty( $post_ids ) ) {
            while ( ! empty( $post_ids ) ) {
                $post_id = array_shift( $post_ids );
                $post_etag = get_post_meta( $post_id, 'spotim_etag', true );

                $stream = $this->request( array(
                    'spot_id' => $this->options->get( 'spot_id' ),
                    'post_id' => $post_id,
                    'etag' => absint( $post_etag ),
                    'count' => 1000,
                    'token' => $this->options->get( 'import_token' )
                ) );

                if ( $stream->is_ok ) {
                    $streams[] = $stream->body;
                } else {
                    $this->response( array(
                        'status' => 'error',
                        'message' => $stream->body
                    ) );
                }
            }
        } else {
            $this->response( array(
                'status' => 'success',
                'message' => __( 'Your website doesn\'t have any publish blog posts', 'wp-spotim' )
            ) );
        }

        return $stream;
    }

    private function merge_comments( $streams ) {
        if ( ! empty( $streams ) ) {
            while ( ! empty( $streams ) ) {
                $stream = array_shift( $streams );

                if ( $stream->from_etag < $stream->new_etag ) {
                    $sync_status = $this->sync_comments(
                        $stream->events,
                        $stream->users,
                        $stream->post_id
                    );

                    if ( ! $sync_status ) {
                        $translated_error = __(
                            'Could not import comments of from this stream: %s', 'wp-spotim'
                        );

                        $this->response( array(
                            'status' => 'error',
                            'message' => sprintf( $translated_error, json_encode( $stream ) )
                        ) );
                    }

                } else if ( $stream->from_etag === $stream->new_etag ) {
                    update_post_meta(
                        $stream->post_id,
                        'spotim_etag',
                        absint( $stream->new_etag ),
                        absint( $stream->from_etag )
                    );
                }
            }
        }

        $this->response( array(
            'status' => 'success',
            'message' => __( 'All comments are up to date.', 'wp-spotim' )
        ) );
    }

    private function request( $query_args ) {
        $url = add_query_arg( $query_args, SPOTIM_EXPORT_URL );

        $result = new stdClass();
        $result->is_ok = false;

        $response = wp_remote_get( $url, array( 'sslverify' => true ) );

        if ( ! is_wp_error( $response ) &&
             'OK' === wp_remote_retrieve_response_message( $response ) &&
             200 === wp_remote_retrieve_response_code( $response ) ) {

            $response_body = json_decode( wp_remote_retrieve_body( $response ) );

            if ( isset( $response_body->success ) && false === $response_body->success ) {
                $result->is_ok = false;
            } else {
                $result->is_ok = true;
                $result->body = $response_body;
            }
        }

        if ( ! $result->is_ok ) {
            $translated_error = __( 'Retriving data failed from this URL: %s', 'wp-spotim' );

            $result->body = sprintf( $translated_error, esc_attr( $url ) );
        }

        return $result;
    }

    public function response( $args = array() ) {
        $defaults = array(
            'status' => '',
            'message' => ''
        );

        if ( ! empty( $args ) ) {
            $args = array_merge( $defaults, $args );

            if ( ! empty( $args['status'] ) && ! empty( $args['message'] ) ) {
                $escaped_message = sanitize_text_field( $args['message'] );

                switch( $args['status'] ) {
                    case 'success':
                        wp_send_json_success( $escaped_message );
                        break;
                    case 'error':
                        wp_send_json_error( $escaped_message );
                        break;
                }
            }
        }
    }

    private function request_mock() {
        $retrieved_body = wp_remote_retrieve_body(
            wp_remote_get( JSONSTUB_EXPORT_URL, array(
                'headers' => array(
                    'JsonStub-User-Key'     => '0fce8d12-9e2c-45c9-9284-e8c6d57a6fe1',
                    'JsonStub-Project-Key'  => '08e0f77f-5dce-4576-b3b2-4f3ed49c1e67',
                    'Content-Type'          => 'application/json'
                )
            ) )
        );

        $data = json_decode( $retrieved_body );
        $data->is_ok = true;

        return $data;
    }

    private function get_post_ids() {
        $args = array(
            'posts_per_page' => -1,
            'post_type' => 'post',
            'post_status' => 'publish',
            'fields' => 'ids'
        );

        return get_posts( $args );
    }



    private function sync_comments( $events, $users, $post_id ) {
        $flag = true;

        if ( ! empty( $events ) ) {
            foreach ( $events as $event ) {

                switch ( $event->type ) {
                    case 'c+':
                    case 'r+':
                        $flag = $this->add_new_comment( $event->message, $users, $post_id );
                        break;
                    case 'c~':
                    case 'r~':
                        $flag = $this->update_comment( $event->message, $users, $post_id );
                        break;
                    case 'c-':
                    case 'r-':
                        $flag = $this->delete_comment( $event->message, $users, $post_id );
                        break;
                    case 'c*':
                        $flag = $this->soft_delete_comment( $event->message, $users, $post_id );
                        break;
                    case 'c@':
                    case 'r@':
                        $flag = $this->anonymous_comment( $event->message, $users, $post_id );
                        break;
                }

                if ( ! $flag ) {
                    break;
                }
            }
        }

        return $flag;
    }

    private function add_new_comment( $sp_message, $sp_users, $post_id ) {
        $comment_created = false;

        $message = new SpotIM_Message( 'new', $sp_message, $sp_users, $post_id );

        if ( ! $message->is_comment_exists() ) {
            $comment_id = wp_insert_comment( $message->get_comment_data() );

            if ( $comment_id ) {
                $comment_created = $message->update_messages_map( $comment_id );
            }
        }

        return !! $comment_created;
    }

    private function update_comment( $sp_message, $sp_users, $post_id ) {
        $comment_updated = false;

        $message = new SpotIM_Message( 'update', $sp_message, $sp_users, $post_id );

        if ( $message->is_comment_exists() ) {
            $comment_updated = wp_update_comment( $message->get_comment_data() );
        }

        return !! $comment_updated;
    }

    private function delete_comment( $message, $users, $post_id ) {
        $comment_deleted = false;
        $message_deleted_from_map = false;

        $message = new SpotIM_Message( 'delete', $sp_message, $sp_users, $post_id );

        if ( $message->is_comment_exists() ) {
            $messages_ids = $message->get_message_and_children_ids_map();

            foreach( $messages_ids as $message_id => $comment_id ) {
                $comment_deleted = wp_delete_comment( $comment_id, true );

                if ( $comment_deleted ) {
                    $message_deleted_from_map = $message->delete_from_messages_map( $message_id );

                    if ( !! $message_deleted_from_map ) {
                        break;
                    }
                } else {
                    break;
                }
            }
        }

        return !! $comment_deleted && !! $message_deleted_from_map;
    }

    private function soft_delete_comment( $sp_message, $sp_users, $post_id ) {
        $comment_soft_deleted = false;

        $message = new SpotIM_Message( 'soft_delete', $sp_message, $sp_users, $post_id );

        if ( $message->is_comment_exists() ) {
            $comment_soft_deleted = wp_update_comment( $message->get_comment_data() );
        }

        return !! $comment_soft_deleted;
    }

    private function anonymous_comment( $sp_message, $sp_users, $post_id ) {
        $comment_anonymized = false;

        $message = new SpotIM_Message( 'anonymous_comment', $sp_message, $sp_users, $post_id );

        if ( $message->is_comment_exists() ) {
            $comment_anonymized = wp_update_comment( $message->get_comment_data() );
        }

        return !! $comment_anonymized;
    }
}