import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import '../config/theme.dart';
import '../services/api_client.dart';

/// Images privées (photo de profil, pièces KYC) : elles exigent le jeton de
/// session, donc Image.network ne peut pas les afficher. On les télécharge
/// avec Dio et on les garde en mémoire.
class FpPrivateImages {
  static final Map<String, Uint8List?> _cache = {};

  static Future<Uint8List?> load(String path, {bool refresh = false}) async {
    if (!refresh && _cache.containsKey(path)) return _cache[path];
    try {
      final r = await ApiClient().dio.get<List<int>>(path, options: Options(responseType: ResponseType.bytes));
      final bytes = r.data == null || r.data!.isEmpty ? null : Uint8List.fromList(r.data!);
      _cache[path] = bytes;
      return bytes;
    } catch (_) {
      _cache[path] = null;
      return null;
    }
  }

  /// À appeler après un nouvel envoi de photo ou à la déconnexion.
  static void clear() => _cache.clear();
}

/// Avatar de l'utilisateur connecté : sa photo de profil, sinon ses initiales.
class FpUserAvatar extends StatefulWidget {
  final double radius;
  final String initials;
  final Color background;
  final Color foreground;
  const FpUserAvatar({super.key, this.radius = 32, this.initials = '', this.background = Colors.white, this.foreground = FpColors.navy});

  @override
  State<FpUserAvatar> createState() => _FpUserAvatarState();
}

class _FpUserAvatarState extends State<FpUserAvatar> {
  Uint8List? _photo;

  @override
  void initState() {
    super.initState();
    FpPrivateImages.load('/me/photo').then((b) {
      if (mounted && b != null) setState(() => _photo = b);
    });
  }

  @override
  Widget build(BuildContext context) => CircleAvatar(
        radius: widget.radius,
        backgroundColor: widget.background,
        backgroundImage: _photo != null ? MemoryImage(_photo!) : null,
        child: _photo != null
            ? null
            : (widget.initials.isNotEmpty
                ? Text(widget.initials, style: TextStyle(color: widget.foreground, fontSize: widget.radius * .68, fontWeight: FontWeight.w500))
                : Icon(Icons.person, color: widget.foreground, size: widget.radius)),
      );
}

/// Miniature d'une pièce KYC envoyée par l'utilisateur (touchez pour agrandir).
class FpKycThumb extends StatefulWidget {
  final int documentId;
  final double size;
  final bool round;
  const FpKycThumb({super.key, required this.documentId, this.size = 48, this.round = false});

  @override
  State<FpKycThumb> createState() => _FpKycThumbState();
}

class _FpKycThumbState extends State<FpKycThumb> {
  Uint8List? _bytes;
  bool _done = false;

  @override
  void initState() {
    super.initState();
    FpPrivateImages.load('/kyc/documents/${widget.documentId}/file').then((b) {
      if (mounted) setState(() {
        _bytes = b;
        _done = true;
      });
    });
  }

  @override
  Widget build(BuildContext context) {
    final s = widget.size;
    final radius = BorderRadius.circular(widget.round ? s : 8);
    Widget child;
    if (_bytes != null) {
      child = Image.memory(_bytes!, width: s, height: s, fit: BoxFit.cover, errorBuilder: (_, __, ___) => Icon(Icons.picture_as_pdf_outlined, color: FpColors.navy));
    } else if (_done) {
      child = const Icon(Icons.broken_image_outlined, color: Colors.black38);
    } else {
      child = const Padding(padding: EdgeInsets.all(14), child: CircularProgressIndicator(strokeWidth: 2));
    }
    return GestureDetector(
      onTap: _bytes == null
          ? null
          : () => showDialog(
                context: context,
                builder: (ctx) => Dialog(
                  insetPadding: EdgeInsets.all(16),
                  child: InteractiveViewer(child: Image.memory(_bytes!, fit: BoxFit.contain)),
                ),
              ),
      child: ClipRRect(
        borderRadius: radius,
        child: Container(width: s, height: s, color: Color(0xFFF1F5F9), alignment: Alignment.center, child: child),
      ),
    );
  }
}
