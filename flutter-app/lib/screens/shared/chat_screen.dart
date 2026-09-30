import 'dart:async';
import 'dart:typed_data';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:url_launcher/url_launcher.dart';
import '../../config/theme.dart';
import '../../services/api_client.dart';
import '../../services/chat_service.dart';
import '../../widgets/fp_avatar.dart';
import '../../widgets/fp_ui.dart';
import '../../l10n/l10n.dart';

/// Discussion avec un utilisateur FlashPay : texte, photos / captures d'écran
/// (3 Mo max) et vidéos courtes (5 Mo max). Rafraîchie toutes les 4 secondes.
class ChatScreen extends StatefulWidget {
  final int conversationId;
  final Map<String, dynamic> contact;
  const ChatScreen({super.key, required this.conversationId, required this.contact});

  @override
  State<ChatScreen> createState() => _ChatScreenState();
}

class _ChatScreenState extends State<ChatScreen> {
  final _service = ChatService();
  final _picker = ImagePicker();
  final _input = TextEditingController();
  final _scroll = ScrollController();
  final List<Map<String, dynamic>> _messages = [];
  bool _loading = true;
  bool _sending = false;
  double? _progress; // envoi d'un fichier
  Timer? _timer;

  int get _lastId => _messages.isEmpty ? 0 : fpInt(_messages.last['id']);

  @override
  void initState() {
    super.initState();
    _load();
    _timer = Timer.periodic(Duration(seconds: 4), (_) => _fetchNew());
  }

  @override
  void dispose() {
    _timer?.cancel();
    _input.dispose();
    _scroll.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final rows = await _service.messages(widget.conversationId);
      if (!mounted) return;
      setState(() {
        _messages
          ..clear()
          ..addAll(rows);
        _loading = false;
      });
      _toBottom();
    } catch (e) {
      if (!mounted) return;
      setState(() => _loading = false);
      fpSnack(context, apiErrorMessage(e), error: true);
    }
  }

  Future<void> _fetchNew() async {
    if (_loading) return;
    try {
      final rows = await _service.messages(widget.conversationId, after: _lastId);
      if (!mounted || rows.isEmpty) return;
      final known = _messages.map((m) => fpInt(m['id'])).toSet();
      setState(() => _messages.addAll(rows.where((m) => !known.contains(fpInt(m['id'])))));
      _toBottom();
    } catch (_) {/* réseau : on réessaie */}
  }

  void _toBottom() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (_scroll.hasClients) _scroll.animateTo(_scroll.position.maxScrollExtent, duration: Duration(milliseconds: 250), curve: Curves.easeOut);
    });
  }

  void _append(Map<String, dynamic> m) {
    if (_messages.any((x) => fpInt(x['id']) == fpInt(m['id']))) return;
    setState(() => _messages.add(m));
    _toBottom();
  }

  Future<void> _sendText() async {
    final text = _input.text.trim();
    if (text.isEmpty || _sending) return;
    setState(() => _sending = true);
    try {
      final m = await _service.sendText(widget.conversationId, text);
      _input.clear();
      _append(m);
    } catch (e) {
      if (mounted) fpSnack(context, apiErrorMessage(e), error: true);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  Future<void> _attach() async {
    final choice = await showModalBottomSheet<String>(
      context: context,
      showDragHandle: true,
      builder: (ctx) => SafeArea(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          ListTile(leading: Icon(Icons.photo_library_outlined), title: Text(tr('Photo ou capture d\'écran')), subtitle: Text(tr('Depuis la galerie · 3 Mo max')), onTap: () => Navigator.pop(ctx, 'gallery')),
          ListTile(leading: Icon(Icons.photo_camera_outlined), title: Text(tr('Prendre une photo')), onTap: () => Navigator.pop(ctx, 'camera')),
          ListTile(leading: Icon(Icons.video_library_outlined), title: Text(tr('Vidéo')), subtitle: Text(tr('Courte : 5 Mo max (environ 20 à 30 s)')), onTap: () => Navigator.pop(ctx, 'video')),
          ListTile(leading: Icon(Icons.videocam_outlined), title: Text(tr('Filmer une vidéo')), subtitle: Text(tr('30 secondes max')), onTap: () => Navigator.pop(ctx, 'record')),
        ]),
      ),
    );
    if (choice == null) return;

    XFile? file;
    try {
      file = switch (choice) {
        'gallery' => await _picker.pickImage(source: ImageSource.gallery, imageQuality: 75, maxWidth: 1600),
        'camera' => await _picker.pickImage(source: ImageSource.camera, imageQuality: 75, maxWidth: 1600),
        'video' => await _picker.pickVideo(source: ImageSource.gallery, maxDuration: Duration(seconds: 30)),
        _ => await _picker.pickVideo(source: ImageSource.camera, maxDuration: Duration(seconds: 30)),
      };
    } catch (e) {
      if (mounted) fpSnack(context, "Impossible d'accéder aux fichiers : autorisez l'accès dans les réglages du téléphone.", error: true);
      return;
    }
    if (file == null) return;

    final isVideo = choice == 'video' || choice == 'record';
    final bytes = await file.readAsBytes();
    final max = isVideo ? ChatService.videoMaxBytes : ChatService.imageMaxBytes;
    if (bytes.length > max) {
      if (mounted) fpSnack(context, isVideo ? 'Vidéo trop lourde : 5 Mo maximum. Choisissez une vidéo plus courte.' : 'Image trop lourde : 3 Mo maximum.', error: true);
      return;
    }
    var name = file.name.isNotEmpty ? file.name : (isVideo ? 'video.mp4' : 'photo.jpg');
    if (!name.contains('.')) name = isVideo ? '$name.mp4' : '$name.jpg';

    setState(() => _progress = 0);
    try {
      final caption = _input.text.trim();
      final m = await _service.sendFile(widget.conversationId, bytes, name, body: caption, onProgress: (sent, total) {
        if (mounted && total > 0) setState(() => _progress = sent / total);
      });
      if (caption.isNotEmpty) _input.clear();
      _append(m);
    } catch (e) {
      if (mounted) fpSnack(context, apiErrorMessage(e), error: true);
    } finally {
      if (mounted) setState(() => _progress = null);
    }
  }

  String _time(String? iso) {
    final d = DateTime.tryParse(iso ?? '')?.toLocal();
    return d == null ? '' : '${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';
  }

  @override
  Widget build(BuildContext context) {
    final name = '${widget.contact['name'] ?? 'Contact'}';
    return Scaffold(
      appBar: AppBar(
        titleSpacing: 0,
        title: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(name, style: TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
          if (widget.contact['phone'] != null) Text('+${'${widget.contact['phone']}'.replaceAll('+', '')}', style: TextStyle(fontSize: 12, color: Colors.black54)),
        ]),
      ),
      body: SafeArea(
        child: Column(children: [
          Expanded(
            child: _loading
                ? Center(child: CircularProgressIndicator())
                : _messages.isEmpty
                    ? Center(child: Padding(padding: EdgeInsets.all(24), child: Text(tr('Dites bonjour 👋\nVous pouvez aussi envoyer une photo, une capture ou une vidéo.'), textAlign: TextAlign.center, style: TextStyle(color: Colors.black54))))
                    : ListView.builder(
                        controller: _scroll,
                        padding: EdgeInsets.fromLTRB(12, 12, 12, 8),
                        itemCount: _messages.length,
                        itemBuilder: (_, i) => _Bubble(message: _messages[i], time: _time('${_messages[i]['at'] ?? ''}')),
                      ),
          ),
          if (_progress != null) LinearProgressIndicator(value: _progress == 0 ? null : _progress, minHeight: 3),
          Container(
            padding: EdgeInsets.fromLTRB(6, 6, 8, 8),
            color: Colors.white,
            child: Row(children: [
              IconButton(tooltip: tr('Joindre une photo ou une vidéo'), onPressed: _progress == null ? _attach : null, icon: Icon(Icons.add_photo_alternate_outlined, color: FpColors.navy)),
              Expanded(
                child: TextField(
                  controller: _input,
                  minLines: 1,
                  maxLines: 4,
                  textCapitalization: TextCapitalization.sentences,
                  decoration: InputDecoration(hintText: tr('Écrire un message…'), isDense: true),
                  onSubmitted: (_) => _sendText(),
                ),
              ),
              SizedBox(width: 6),
              IconButton.filled(
                onPressed: _sending ? null : _sendText,
                style: IconButton.styleFrom(backgroundColor: FpColors.red),
                icon: _sending ? SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : Icon(Icons.send_rounded, color: Colors.white),
              ),
            ]),
          ),
        ]),
      ),
    );
  }
}

class _Bubble extends StatelessWidget {
  final Map<String, dynamic> message;
  final String time;
  const _Bubble({required this.message, required this.time});

  @override
  Widget build(BuildContext context) {
    final mine = message['mine'] == true;
    final type = '${message['type']}';
    final body = (message['body'] ?? '').toString();
    final bg = mine ? FpColors.navy : Colors.white;
    final fg = mine ? Colors.white : FpColors.ink;

    return Align(
      alignment: mine ? Alignment.centerRight : Alignment.centerLeft,
      child: Container(
        margin: EdgeInsets.symmetric(vertical: 3),
        constraints: BoxConstraints(maxWidth: MediaQuery.of(context).size.width * .78),
        padding: EdgeInsets.all(type == 'text' ? 11 : 5),
        decoration: BoxDecoration(
          color: bg,
          borderRadius: BorderRadius.only(
            topLeft: Radius.circular(16),
            topRight: Radius.circular(16),
            bottomLeft: Radius.circular(mine ? 16 : 4),
            bottomRight: Radius.circular(mine ? 4 : 16),
          ),
          border: mine ? null : Border.all(color: FpColors.line),
        ),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
          if (type == 'image') _ChatImage(messageId: fpInt(message['id'])),
          if (type == 'video') _ChatVideo(messageId: fpInt(message['id']), mine: mine),
          if (body.isNotEmpty)
            Padding(
              padding: EdgeInsets.fromLTRB(type == 'text' ? 0 : 6, type == 'text' ? 0 : 6, type == 'text' ? 0 : 6, 0),
              child: Text(body, style: TextStyle(color: fg, fontSize: 15)),
            ),
          Padding(
            padding: EdgeInsets.only(top: 3, right: type == 'text' ? 0 : 6, bottom: type == 'text' ? 0 : 2),
            child: Row(mainAxisSize: MainAxisSize.min, children: [
              Text(time, style: TextStyle(fontSize: 10.5, color: mine ? Colors.white70 : Colors.black45)),
              if (mine) ...[
                SizedBox(width: 3),
                Icon(message['read'] == true ? Icons.done_all_rounded : Icons.done_rounded, size: 14, color: Colors.white70),
              ],
            ]),
          ),
        ]),
      ),
    );
  }
}

class _ChatImage extends StatefulWidget {
  final int messageId;
  const _ChatImage({required this.messageId});

  @override
  State<_ChatImage> createState() => _ChatImageState();
}

class _ChatImageState extends State<_ChatImage> {
  Uint8List? _bytes;
  bool _failed = false;

  @override
  void initState() {
    super.initState();
    FpPrivateImages.load(ChatService.filePath(widget.messageId)).then((b) {
      if (mounted) setState(() { _bytes = b; _failed = b == null; });
    });
  }

  @override
  Widget build(BuildContext context) {
    if (_failed) {
      return SizedBox(width: 200, height: 60, child: Center(child: Text(tr('Image indisponible'), style: TextStyle(color: Colors.black45))));
    }
    if (_bytes == null) {
      return SizedBox(width: 200, height: 150, child: Center(child: CircularProgressIndicator(strokeWidth: 2)));
    }
    return GestureDetector(
      onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => _FullImage(bytes: _bytes!))),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(12),
        child: Image.memory(_bytes!, width: 230, fit: BoxFit.cover),
      ),
    );
  }
}

class _FullImage extends StatelessWidget {
  final Uint8List bytes;
  const _FullImage({required this.bytes});

  @override
  Widget build(BuildContext context) => Scaffold(
        backgroundColor: Colors.black,
        appBar: AppBar(backgroundColor: Colors.black, foregroundColor: Colors.white),
        body: Center(child: InteractiveViewer(maxScale: 5, child: Image.memory(bytes))),
      );
}

class _ChatVideo extends StatefulWidget {
  final int messageId;
  final bool mine;
  const _ChatVideo({required this.messageId, required this.mine});

  @override
  State<_ChatVideo> createState() => _ChatVideoState();
}

class _ChatVideoState extends State<_ChatVideo> {
  bool _opening = false;

  Future<void> _play() async {
    setState(() => _opening = true);
    try {
      final url = await ChatService().mediaLink(widget.messageId);
      final ok = await launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
      if (!ok && mounted) fpSnack(context, 'Aucune application ne peut lire cette vidéo.', error: true);
    } catch (e) {
      if (mounted) fpSnack(context, apiErrorMessage(e), error: true);
    } finally {
      if (mounted) setState(() => _opening = false);
    }
  }

  @override
  Widget build(BuildContext context) => InkWell(
        onTap: _opening ? null : _play,
        borderRadius: BorderRadius.circular(12),
        child: Container(
          width: 230,
          height: 130,
          decoration: BoxDecoration(color: Colors.black87, borderRadius: BorderRadius.circular(12)),
          child: Center(
            child: _opening
                ? CircularProgressIndicator(color: Colors.white)
                : Column(mainAxisSize: MainAxisSize.min, children: [
                    Icon(Icons.play_circle_fill_rounded, color: Colors.white, size: 52),
                    SizedBox(height: 4),
                    Text(tr('Lire la vidéo'), style: TextStyle(color: Colors.white70, fontSize: 12)),
                  ]),
          ),
        ),
      );
}
