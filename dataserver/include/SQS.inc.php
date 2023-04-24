<?php
/*
    ***** BEGIN LICENSE BLOCK *****
    
    This file is part of the Zotero Data Server.
    
    Copyright © 2013 Center for History and New Media
                     George Mason University, Fairfax, Virginia, USA
                     http://zotero.org
    
    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.
    
    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Affero General Public License for more details.
    
    You should have received a copy of the GNU Affero General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
    
    ***** END LICENSE BLOCK *****
*/

class Z_SQS {
	private static $sqs;
	
	public static function send($queueURL, $message) {
		self::load();
		Z_Core::debug("Sending SQS message to $queueURL", 4);
		$response = self::$sqs->sendMessage([
			'QueueUrl' => $queueURL,
			'MessageBody' => $message
		]);
		return $response;
	}
	
	
	public static function sendBatch($queueURL, $messages) {
		self::load();
		
		if (sizeOf($messages) > 10) {
			throw new Exception("Only 10 messages can be sent at a time");
		}
		
		$num = sizeOf($messages);
		Z_Core::debug("Sending " . $num . " message to SQS" . ($num === 1 ? "" : "s"));
		
		$entries = array();
		foreach ($messages as $message) {
			$entries[] = array(
				'Id' => uniqid(),
				'MessageBody' => $message
			);
		}
		
		$response = self::$sqs->sendMessageBatch([
			'QueueUrl' => $queueURL,
			'Entries' => $entries
		]);
		return $response;
	}
	
	
	public static function receive($queueURL) {
		self::load();
		$response = self::$sqs->receiveMessage([
			'QueueUrl' => $queueURL
		]);
		return $response;
	}
	
	
	public static function delete($queueURL, $receiptHandle) {
		$response = self::$sqs->deleteMessage([
			'QueueUrl' => $queueURL,
			'ReceiptHandle' => $receiptHandle
		]);
		return $response;
	}
	
	
	public static function deleteBatch($queueURL, $batchEntries) {
		Z_Core::debug("Deleting " . sizeOf($batchEntries) . " messages from $queueURL", 4);
		$response = self::$sqs->deleteMessageBatch([
			'QueueUrl' => $queueURL,
			'Entries' => $batchEntries
		]);
		$response = self::processResponse($response);
		if (!$response) {
			return false;
		}
		foreach ($response->body->DeleteMessageBatchResult[0]->BatchResultErrorEntry as $error) {
			error_log("Error deleting SQS message: "
				. $error->Code . ": " . $error->Message);
		}
		return $response->body->DeleteMessageBatchResult[0]->DeleteMessageBatchResultEntry->count();
	}
	
	
	private static function load() {
		if (!self::$sqs) {
			self::$sqs = Z_Core::$AWS->get('sqs');
		}
	}
}
