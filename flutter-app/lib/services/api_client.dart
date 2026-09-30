import 'dart:math';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import '../config/constants.dart';
import '../config/navigation.dart';
import '../widgets/pin_sheet.dart';

/// Client HTTP centralisé (Dio) :
///  - jeton Sanctum de l'appareil ;
///  - clé d'idempotence sur chaque POST (§13.1 : pas de double débit si le
///    réseau coupe et que l'utilisateur relance) ;
///  - confirmation PIN (§4.5) : sur une réponse 428 « pin_required » la saisie
///    du PIN s'affiche en bottom-sheet puis la requête est rejouée ; « pin_not_set »
///    propose de créer le PIN ; « pin_invalid » redemande le code (3 essais).
class ApiClient {
  static final ApiClient _instance = ApiClient._internal();
  factory ApiClient() => _instance;

  late final Dio dio;
  final _storage = const FlutterSecureStorage();
  void Function()? onUnauthorized;
  String? _deviceId;

  ApiClient._internal() {
    dio = Dio(BaseOptions(
      baseUrl: kApiBaseUrl,
      connectTimeout: const Duration(seconds: 15),
      receiveTimeout: const Duration(seconds: 30),
      headers: {'Accept': 'application/json'},
    ));

    dio.interceptors.add(InterceptorsWrapper(
      onRequest: (options, handler) async {
        final token = await _storage.read(key: 'flashpay_token');
        if (token != null) {
          options.headers['Authorization'] = 'Bearer $token';
        }
        options.headers['X-Device-Id'] = await deviceId();
        if (options.method == 'POST' && !options.headers.containsKey('Idempotency-Key')) {
          options.headers['Idempotency-Key'] = _randomKey();
        }
        handler.next(options);
      },
      onError: (error, handler) async {
        final status = error.response?.statusCode;
        final data = error.response?.data;
        final code = data is Map ? data['code']?.toString() : null;

        if (status == 401) {
          onUnauthorized?.call();
          return handler.next(error);
        }

        final isPinError = (status == 428 && (code == 'pin_required' || code == 'pin_not_set')) || (status == 422 && code == 'pin_invalid');
        final tries = (error.requestOptions.extra['pin_tries'] as int?) ?? 0;
        final ctx = fpNavigatorKey.currentContext;
        if (!isPinError || ctx == null || tries >= 3) {
          return handler.next(error);
        }

        String? pin;
        if (code == 'pin_not_set') {
          pin = await PinSheet.ask(ctx, confirmMode: true, subtitle: 'Il protège toutes vos opérations (envoi, paiement, retrait).');
          if (pin == null) return handler.next(error);
          try {
            await dio.post('/me/pin', data: {'pin': pin});
          } on DioException catch (e) {
            return handler.next(e);
          }
        } else {
          final retry = code == 'pin_invalid';
          pin = await PinSheet.ask(ctx, error: retry ? data['message']?.toString() : null);
          if (pin == null) return handler.next(error);
        }

        final opts = error.requestOptions;
        opts.headers['X-FlashPay-Pin'] = pin;
        opts.extra['pin_tries'] = tries + 1;
        try {
          final res = await dio.fetch(opts);
          return handler.resolve(res);
        } on DioException catch (e) {
          return handler.next(e);
        }
      },
    ));
  }

  String _randomKey() {
    final r = Random.secure();
    return List.generate(24, (_) => r.nextInt(16).toRadixString(16)).join();
  }

  /// Identifiant stable de l'appareil (politique multi-appareils, §15).
  Future<String> deviceId() async {
    if (_deviceId != null) return _deviceId!;
    var id = await _storage.read(key: 'flashpay_device_id');
    if (id == null) {
      id = 'fp-${_randomKey()}';
      await _storage.write(key: 'flashpay_device_id', value: id);
    }
    _deviceId = id;
    return id;
  }

  Future<void> saveToken(String token) => _storage.write(key: 'flashpay_token', value: token);
  Future<String?> readToken() => _storage.read(key: 'flashpay_token');
  Future<void> clearToken() => _storage.delete(key: 'flashpay_token');
}

/// Extrait un message d'erreur lisible depuis une DioException.
String apiErrorMessage(Object error) {
  if (error is DioException) {
    final data = error.response?.data;
    if (data is Map && data['message'] != null) return data['message'].toString();
    if (data is Map && data['error'] is Map && data['error']['message'] != null) return data['error']['message'].toString();
    if (data is Map && data['errors'] != null) {
      final errors = data['errors'] as Map;
      return errors.values.first is List ? errors.values.first.first.toString() : errors.values.first.toString();
    }
    if (error.type == DioExceptionType.connectionTimeout || error.type == DioExceptionType.receiveTimeout || error.type == DioExceptionType.connectionError) {
      // §14 : perte de connexion pendant une opération — pas de double envoi
      return 'Connexion perdue. Vérifiez votre historique avant de réessayer : aucune opération ne sera débitée deux fois.';
    }
    final code = error.response?.statusCode;
    if (code != null) return 'Le serveur FlashPay a répondu une erreur (code $code). Réessayez dans un instant.';
    return 'Une erreur est survenue. Vérifiez votre connexion.';
  }
  // Erreur interne à l'application (et non réseau) : on la trace pour le diagnostic.
  debugPrint('FlashPay — erreur inattendue : $error');
  return 'Une erreur inattendue est survenue. Réessayez.';
}

/// Code d'erreur métier renvoyé par l'API (limit_exceeded, kyc_required…).
String? apiErrorCode(Object error) {
  if (error is DioException && error.response?.data is Map) {
    return (error.response!.data as Map)['code']?.toString();
  }
  return null;
}

/// Petit utilitaire d'affichage d'un message.
void fpSnack(BuildContext context, String message, {bool error = false}) {
  ScaffoldMessenger.of(context).showSnackBar(SnackBar(
    content: Text(message),
    backgroundColor: error ? const Color(0xFFDC2626) : null,
  ));
}
