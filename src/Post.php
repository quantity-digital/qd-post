<?php

namespace QD;

/**
 * Helpers for querying posts with Advanced Custom Fields
 */
class Post
{
    /**
     * Returns a list of posts with ACF fields added in the $post->fields property.
     * Uses get_posts, but defaults to post_type = any.
     * Refer to https://codex.wordpress.org/Class_Reference/WP_Query#Parameters
     */
    public static function getPosts($queryParams)
    {
        $defaults = array(
            'post_type' => 'any',
        );

        // Run the query
        $posts = \get_posts(array_merge($defaults, $queryParams));

        // Add ACF fields
        $posts = array_map(function ($post) {
            return self::addACFFields($post);
        }, $posts);

        // Return the posts
        return $posts;
    }
    /**
     * Returns a list of posts with ACF fields added in the $post->fields property.
     * Uses get_posts, but defaults to post_type = any.
     * Refer to https://codex.wordpress.org/Class_Reference/WP_Query#Parameters
     */
    public static function getPostsWithSearchWP($queryParams)
    {
        $defaults = array(
            'post_type' => 'any',
        );

        // Run the query
        $posts = new \SWP_Query(array_merge($defaults, $queryParams));

        // Add ACF fields
        $posts = array_map(function ($post) {
            return self::addACFFields($post);
        }, $posts->posts);

        // Return the posts
        return $posts;
    }

    /**
     * Returns a single post with ACF fields added in the $post->fields property.
     * Returns false if no post is found
     */
    public static function getPost($queryParams)
    {
        // Ensure to only return a single results
        $queryParams = array_merge($queryParams, array('numberposts' => 1));

        // Get the posts
        $posts = self::getPosts($queryParams);

        // Only return a single post or false, if not found
        if (count($posts) > 0) {
            return $posts[0];
        } else {
            return false;
        }
    }

    /**
     * Returns a single post with ACF fields added in the $post->fields property.
     * Returns false if no post is found
     */
    public static function getPostById($id)
    {
        // Get the post
        $post = \get_post($id);

        // Add the ACF fields if found, otherwise return false
        if ($post) {
            return self::addACFFields($post);
        } else {
            return false;
        }
    }

    /**
     * Updates several ACF fields on the post.
     */
    public static function updateFields($postId, $fields)
    {
        foreach ($fields as $field_key => $value) {
            \update_field($field_key, $value, $postId);
        }
    }

    /**
     * Inserts or updates (if ID is given) a post according to $postFields.
     * Attaches fields in $customFields as ACF fields.
     * Returns the ID of the post if success, false if the insert failed.
     */
    public static function insertPost($postFields, $customFields)
    {
        $postId = \wp_insert_post($postFields, false);

        if ($postId) {
            self::updateFields($postId, $customFields);
            return $postId;
        } else {
            return false;
        }
    }


    /**
     * Deletes a post, optionally with attachments.
     * Attaches fields in $customFields as ACF fields.
     * Returns the true on success, false on failure.
     */
    public static function deletePost($postId, $softDelete = true, $deleteAttachments = false)
    {
        if ($deleteAttachments) {
            $deleteAttachmentsSuccess = self::deleteAttachments($postId, $softDelete);

            if (!$deleteAttachmentsSuccess) {
                return false;
            }
        }

        // Delete the post
        $success = \wp_delete_post($postId, !$softDelete);

        if ($success) {
            return true;
        } else {
            return false;
        }
    }


    /**
     * Inserts or updates (if ID is given) a post according to $postFields.
     * Attaches fields in $customFields as ACF fields.
     * Returns the ID of the post if success, false if the insert failed.
     */
    public static function deleteAttachments($postId, $softDelete = true)
    {
        // Get the attachments
        $query = array(
            'post_type'         => 'attachment',
            'post_status'       => 'any',
            'posts_per_page'    => -1,
            'post_parent'       => $postId
        );
        $attachments = \get_posts($query);

        // Assume success
        $success = true;

        // Delete each attachment
        foreach ($attachments as $attachment) {
            $deleteSuccess = \wp_delete_attachment($attachment->ID, !$softDelete);
            $success = $success && $deleteSuccess !== false;
        }

        // Return success if all succeeded, false if any failed
        return $success;
    }

    /**
     * Handles a file upload and attaches it to the post with $postId.
     * $fileArrayKey refers to the key in the $_FILES array.
     * If $customField is set, the custom field will be updated with the attachment url.
     */
    public static function attachUpload($postId, $fileArrayKey, $customField = false)
    {
        // Needed for WP upload functionality.
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attachmentId = \media_handle_upload($fileArrayKey, $postId);

        if (\is_wp_error($attachmentId)) {
            return false;
        } else {
            // Add file url to custom fields
            if (!empty($customField)) {
                \update_field($customField, wp_get_attachment_url($attachmentId), $postId);
            }

            return $attachmentId;
        }
    }

    /**
     * Handles multiple file uploads and attaches them to the post with $postId.
     * $fileArrayKey refers to the key in the $_FILES array.
     * Returns an array of ( 'name', 'success', 'id' )
     */
    public static function attachUploads($postId, $fileArrayKey)
    {
        if (count($_FILES) === 0 || array_key_exists($fileArrayKey, $_FILES) === false) {
            return new WP_Error('uploadParameterNotSet', 'The upload file parameter did not exists.');
        }

        if (is_array($_FILES[$fileArrayKey]['name']) === false) {
            // Must be a single file, redirect to attachUpload
            return self::attachUpload($postId, $fileArrayKey, false);
        }

        // Create the output array
        $out = array();

        // Save the original $_FILES array
        $originalFiles = $_FILES;

        // Needed for WP upload functionality.
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        // Create a sanitized files array for further manipulation
        $manipulatedFiles = array_map(function ($name, $type, $tmp_name, $error, $size) {
            return array(
                'name' => $name,
                'type' => $type,
                'tmp_name' => $tmp_name,
                'error' => $error,
                'size' => $size,
            );
        }, $_FILES[$fileArrayKey]['name'], $_FILES[$fileArrayKey]['type'], $_FILES[$fileArrayKey]['tmp_name'], $_FILES[$fileArrayKey]['error'], $_FILES[$fileArrayKey]['size']);

        // Filter out "no file" errors
        $manipulatedFiles = array_filter($manipulatedFiles, function ($file) {
            return $file['error'] !== UPLOAD_ERR_NO_FILE;
        });

        // Run through each file and upload
        foreach ($manipulatedFiles as $file) {
            // Skip errors
            if ($file['error'] !== UPLOAD_ERR_OK) {
                // Mark file as error
                $out[] = array('name' => $file['name'], 'success' => false, 'id' => null );
                continue;
            }

            // Manipulate the files array
            $_FILES = array();
            $_FILES[$file['name']] = $file;

            // Run the native WP upload handler
            $attachmentId = \media_handle_upload($file['name'], $postId);

            if (\is_wp_error($attachmentId)) {
                // Mark file as error
                $out[] = array('name' => $file['name'], 'success' => false, 'id' => null );
            } else {
                $out[] = array('name' => $file['name'], 'success' => true, 'id' => $attachmentId );
            }
        }

        // Restore the original $_FILES array
        $_FILES = $originalFiles;

        return $out;
    }

    /**
     * Adds fields property to a WP_Post with all ACF fields from the post
     */
    private static function addACFFields($postObject)
    {
        // Get ACF fields
        $post_fields = \get_fields($postObject->ID);

        // Add fields to fields property
        $postObject->fields = (object)$post_fields;

        // Return post
        return $postObject;
    }
}
