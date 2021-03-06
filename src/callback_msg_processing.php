<?php
function callback_msg_processing($callback) {
    global $memory;

    Logger::debug("Processing callback query", __FILE__);

    $callback_data = $callback['data'];
    $chat_id = $callback['message']['chat']['id'];
    $message_id = $callback['message']['message_id'];

    memory_load_for_user($chat_id);
    localization_load_user($chat_id, (isset($callback['message']['from']['language_code'])) ? $callback['message']['from']['language_code'] : null);

    if(isset($memory->lastCallbackMessageId) && $message_id == $memory->lastCallbackMessageId) {
        // Clear memory
        unset($memory->lastCallbackMessageId);

        if(starts_with($callback_data, 'card ')) {
            cardinal_message_processing($chat_id, $callback_data);
        }
        else if(starts_with($callback_data, 'name ')) {
            name_message_processing($chat_id, $callback_data);
        }
        else if(starts_with($callback_data, 'language ')) {
            localization_message_processing($chat_id, $callback_data);
        }
        else {
            Logger::error("Unknown callback, data: {$callback_data}", __FILE__, $chat_id);
        }
    }
    else {
        Logger::debug("Already processed callback from message ID {$message_id}, ignoring", __FILE__, $chat_id);
    }

    memory_persist($chat_id);
}

function cardinal_message_processing($chat_id, $callback_data){
    // Get cardinal position
    $card_code = substr($callback_data, 5, 1);
    $cardinal_info = substr($callback_data, 7, 1);

    Logger::debug("Position {$card_code}, direction {$cardinal_info}", __FILE__, $chat_id);

    if(cardinal_direction_is_valid($card_code)) {
        telegram_send_message(
            $chat_id,
            sprintf(__("Ok, you’re looking %s!"), cardinal_direction_to_description($card_code))
        );

        // Get current game state
        $user_status = db_scalar_query("SELECT COUNT(*) FROM moves WHERE telegram_id = {$chat_id} AND reached_on IS NOT NULL");
        $user_null_status = db_scalar_query("SELECT COUNT(*) FROM moves WHERE telegram_id = {$chat_id} AND reached_on IS NULL");

        // If user has started a game, get position, else set to step 1
        if ($user_status !== NULL && $user_status !== false) {
            if ($user_null_status != NULL && $user_null_status !== false) {
                $lvl = $user_status + 1;
            } else {
                $lvl = $user_status;
            }
        } else {
            Logger::warning("Can't find user status. Setting user lvl to 1", __FILE__, $chat_id);
            $lvl = 1;
        }

        Logger::debug("Game lvl: {$lvl}");

        // Get target coordinates (any kind)
        $current_coordinate = db_scalar_query("SELECT cell FROM moves WHERE telegram_id = {$chat_id} AND reached_on IS NULL LIMIT 1");
        if(!$current_coordinate) {
            $current_coordinate = db_scalar_query("SELECT cell FROM moves WHERE telegram_id = {$chat_id} AND reached_on IS NOT NULL ORDER BY reached_on DESC LIMIT 1");
        }
        Logger::debug("Expected user coordinates: {$current_coordinate}");

        $expected_direction = get_direction($current_coordinate);
        if($expected_direction !== $card_code) {
            Logger::info("User direction '{$card_code}' does not match expected one '{$current_coordinate}'", __FILE__, $chat_id);

            if($user_null_status >= 1) {
                // User is looking in wrong direction and has an unreached target
                // Remove end of maze position tuple and send back to last position for new maze
                db_perform_action("DELETE FROM moves WHERE telegram_id = {$chat_id} AND reached_on IS NULL");

                $beginning_position = db_scalar_query("SELECT cell FROM moves WHERE telegram_id = {$chat_id} AND reached_on IS NOT NULL ORDER BY reached_on DESC LIMIT 1");
                $last_position_no_direction = get_position_no_direction($beginning_position);
                $last_position_direction = get_direction($beginning_position);

                Logger::info("Sending back to {$last_position_no_direction}-{$last_position_direction}", __FILE__, $chat_id);

                telegram_send_message(
                    $chat_id,
                    __("You’re facing the wrong direction!") . ' 🙁' .
                    sprintf(__("Move back to block <code>%s</code>, face <i>%s</i> and scan the QR Code again."), $last_position_no_direction, cardinal_direction_to_description($last_position_direction)),
                    array("parse_mode" => "HTML")
                );
            }
            else {
                // User is looking in wrong direction, but was already sent back to last step (so we can assume he reached it)
                $beginning_position = db_scalar_query("SELECT cell FROM moves WHERE telegram_id = {$chat_id} AND reached_on IS NOT NULL ORDER BY reached_on DESC LIMIT 1");
                $expected_block = get_position_no_direction($beginning_position);
                $expected_direction = get_direction($beginning_position);

                Logger::info("Adjusting direction to {$expected_block}-{$expected_direction}", __FILE__, $chat_id);

                telegram_send_message(
                    $chat_id,
                    sprintf(__("Please face <i>%s</i>."), cardinal_direction_to_description($expected_direction)),
                    array("parse_mode" => "HTML")
                );

                request_cardinal_position($chat_id, CARD_NOT_ANSWERING_QUIZ);
            }

            return;
        }

        // Update position with arrival timestamp
        db_perform_action("UPDATE `moves` SET `reached_on` = NOW() WHERE `telegram_id` = {$chat_id} AND `reached_on` IS NULL");

        if($cardinal_info == CARD_ENDGAME_POSITION){
            end_of_game($chat_id);
            return;
        }
        if($lvl > 0 && $cardinal_info == CARD_ANSWERING_QUIZ) {
            telegram_send_message(
                $chat_id,
                __("Very well! You have found the right spot.")
            );
        }

        // Prepare instructions for next step
        $new_current_coordinate = db_scalar_query("SELECT cell FROM moves WHERE telegram_id = {$chat_id} AND reached_on IS NOT NULL ORDER BY reached_on DESC LIMIT 1");
        $maze_data = generate_maze($lvl, $chat_id, $new_current_coordinate);
        $maze_arrival_position = $maze_data[1];
        $maze_message = $maze_data[0];
        Logger::info("New instructions for level #{$lvl}: {$maze_data[0]}, destination: {$maze_data[1]}", __FILE__, $chat_id);

        if(!$maze_data || empty($maze_message)) {
            Logger::error("Empty instructions (data: '" . print_r($maze_data, true) . "')", __FILE__, $chat_id);
        }

        $success = db_perform_action("INSERT INTO `moves` (`telegram_id`, `cell`) VALUES($chat_id, '{$maze_arrival_position}')");
        Logger::debug("Success of insertion: {$success}");

        // Send maze
        telegram_send_message(
            $chat_id,
            sprintf(__("<b>%d.</b> Follow these instructions and scan the QR Code at the destination:\n<code>%s</code>"), $lvl, $maze_message),
            array("parse_mode" => "HTML")
        );
    }
    else {
        Logger::error("Invalid callback data: {$callback_data}");
        telegram_send_message($chat_id, __("Invalid code."));
    }
}

function name_message_processing($chat_id, $callback_data) {
    global $memory;

    $data = substr($callback_data, 5);
    if ($data === "error"){
        // Request name again
        $memory->nameRequested = true;
        telegram_send_message($chat_id, __("Write your full name again, please:"));
    }
    else {
        unset($memory->nameRequested);
        send_pdf($chat_id, $data);
    }
}

function localization_message_processing($chat_id, $callback_data) {
    $lang_code = substr($callback_data, mb_strlen('language '));
    localization_switch_and_persist_locale($chat_id, $lang_code);

    telegram_send_message($chat_id, __("Switched language."));
}
