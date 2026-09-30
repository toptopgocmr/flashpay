import 'package:dio/dio.dart';
import 'api_client.dart';

Map<String, dynamic> _map(dynamic d) => Map<String, dynamic>.from(d as Map);
List<Map<String, dynamic>> _list(dynamic d) => ((d ?? []) as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();

/// Messagerie entre utilisateurs FlashPay (texte, photos, vidéos courtes).
class ChatService {
  final Dio _dio = ApiClient().dio;

  static const imageMaxBytes = 3 * 1024 * 1024;
  static const videoMaxBytes = 5 * 1024 * 1024;

  /// {data: [...conversations], unread_total}
  Future<Map<String, dynamic>> conversations() async => _map((await _dio.get('/chats')).data);

  /// Ouvre (ou retrouve) la discussion avec un numéro FlashPay : {id, user}
  Future<Map<String, dynamic>> open(String phone) async => _map((await _dio.post('/chats', data: {'phone': phone})).data);

  Future<List<Map<String, dynamic>>> messages(int conversationId, {int? after, int? before}) async {
    final r = await _dio.get('/chats/$conversationId/messages', queryParameters: {
      if (after != null) 'after': after,
      if (before != null) 'before': before,
    });
    return _list((r.data as Map)['data']);
  }

  Future<Map<String, dynamic>> sendText(int conversationId, String body) async =>
      _map((await _dio.post('/chats/$conversationId/messages', data: {'body': body})).data);

  Future<Map<String, dynamic>> sendFile(int conversationId, List<int> bytes, String filename, {String? body, void Function(int, int)? onProgress}) async {
    final form = FormData.fromMap({
      'file': MultipartFile.fromBytes(bytes, filename: filename),
      if (body != null && body.trim().isNotEmpty) 'body': body.trim(),
    });
    final r = await _dio.post(
      '/chats/$conversationId/messages',
      data: form,
      onSendProgress: onProgress,
      options: Options(sendTimeout: const Duration(minutes: 2), receiveTimeout: const Duration(minutes: 2)),
    );
    return _map(r.data);
  }

  /// Lien temporaire (10 min) pour lire une vidéo dans le lecteur du téléphone.
  Future<String> mediaLink(int messageId) async => '${(await _dio.get('/chats/messages/$messageId/link')).data['url']}';

  static String filePath(int messageId) => '/chats/messages/$messageId/file';
}
