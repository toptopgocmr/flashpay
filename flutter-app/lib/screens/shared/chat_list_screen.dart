import 'dart:async';
import 'package:flutter/material.dart';
import '../../config/theme.dart';
import '../../services/api_client.dart';
import '../../services/chat_service.dart';
import '../../widgets/fp_ui.dart';
import 'chat_screen.dart';
import '../../models/corridor.dart';
import '../../widgets/fp_phone_chips.dart';
import 'jbem_assistant_screen.dart';
import 'support_screen.dart';
import '../../l10n/l10n.dart';

/// Discussions entre utilisateurs FlashPay. Le support (équipe FlashPay) et
/// l'assistant JBEM restent accessibles depuis la barre du haut.
class ChatListScreen extends StatefulWidget {
  const ChatListScreen({super.key});

  @override
  State<ChatListScreen> createState() => _ChatListScreenState();
}

class _ChatListScreenState extends State<ChatListScreen> {
  final _service = ChatService();
  List<Map<String, dynamic>>? _convs;
  String? _error;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _load();
    _timer = Timer.periodic(Duration(seconds: 10), (_) => _load(silent: true));
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> _load({bool silent = false}) async {
    try {
      final r = await _service.conversations();
      if (mounted) setState(() { _convs = ((r['data'] ?? []) as List).map((e) => Map<String, dynamic>.from(e as Map)).toList(); _error = null; });
    } catch (e) {
      if (mounted && !silent) setState(() => _error = apiErrorMessage(e));
    }
  }

  Future<void> _newChat() async {
    final ctrl = TextEditingController();
    FpCountry? country;
    String? error;
    final phone = await showDialog<String>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setD) {
          void submit() {
            final p = fpInternational(country, ctrl.text);
            if (p == null) {
              setD(() => error = tr('Numéro invalide pour ce pays'));
              return;
            }
            Navigator.pop(ctx, p);
          }

          return AlertDialog(
            title: Text(tr('Nouvelle discussion')),
            content: Column(mainAxisSize: MainAxisSize.min, children: [
              Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                FpDialPicker(value: country, onChanged: (c) => setD(() => country = c)),
                const SizedBox(width: 8),
                Expanded(
                  child: TextField(
                    controller: ctrl,
                    autofocus: true,
                    keyboardType: TextInputType.phone,
                    decoration: InputDecoration(labelText: tr('Numéro du contact'), hintText: '06 123 45 67', errorText: error, errorMaxLines: 2),
                    onChanged: (_) {
                      if (error != null) setD(() => error = null);
                    },
                    onSubmitted: (_) => submit(),
                  ),
                ),
              ]),
              const SizedBox(height: 8),
              Text(tr('Le contact doit avoir un compte FlashPay.'), style: const TextStyle(fontSize: 12, color: Colors.black54)),
            ]),
            actions: [
              TextButton(onPressed: () => Navigator.pop(ctx), child: Text(tr('Annuler'))),
              FilledButton(onPressed: submit, child: Text(tr('Discuter'))),
            ],
          );
        },
      ),
    );
    if (phone == null || phone.isEmpty || !mounted) return;
    try {
      final c = await _service.open(phone);
      if (!mounted) return;
      await _openConv(fpInt(c['id']), Map<String, dynamic>.from((c['user'] ?? {}) as Map));
    } catch (e) {
      if (mounted) fpSnack(context, apiErrorMessage(e), error: true);
    }
  }

  Future<void> _openConv(int id, Map<String, dynamic> user) async {
    await Navigator.push(context, MaterialPageRoute(builder: (_) => ChatScreen(conversationId: id, contact: user)));
    _load(silent: true);
  }

  String _initials(String name) {
    final parts = name.trim().split(RegExp(r'\s+')).where((w) => w.isNotEmpty).toList();
    return parts.take(2).map((w) => w[0].toUpperCase()).join();
  }

  String _when(String? iso) {
    if (iso == null) return '';
    final d = DateTime.tryParse(iso)?.toLocal();
    if (d == null) return '';
    final now = DateTime.now();
    if (d.year == now.year && d.month == now.month && d.day == now.day) {
      return '${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';
    }
    return '${d.day.toString().padLeft(2, '0')}/${d.month.toString().padLeft(2, '0')}';
  }

  @override
  Widget build(BuildContext context) {
    final convs = _convs;
    return Scaffold(
      appBar: AppBar(
        title: Text(tr('Chat')),
        actions: [
          IconButton(
            tooltip: tr('Assistant JBEM'),
            icon: Icon(Icons.smart_toy_outlined),
            onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => JbemAssistantScreen())),
          ),
          IconButton(
            tooltip: tr('Support FlashPay'),
            icon: Icon(Icons.headset_mic_outlined),
            onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => SupportScreen())),
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _newChat,
        icon: Icon(Icons.edit_outlined),
        label: Text(tr('Nouvelle discussion')),
      ),
      body: convs == null
          ? Center(child: _error != null ? Padding(padding: EdgeInsets.all(24), child: Text(_error!, textAlign: TextAlign.center)) : CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: EdgeInsets.only(bottom: 90),
                children: [
                  if (convs.isEmpty)
                    Padding(
                      padding: EdgeInsets.only(top: 60),
                      child: FpEmpty('Aucune discussion.\nÉcrivez à un contact FlashPay : texte, photos, captures ou vidéos.', icon: Icons.forum_outlined),
                    ),
                  ...convs.map((c) {
                    final u = Map<String, dynamic>.from((c['user'] ?? {}) as Map);
                    final last = c['last_message'] is Map ? Map<String, dynamic>.from(c['last_message'] as Map) : null;
                    final unread = fpInt(c['unread']);
                    final name = '${u['name'] ?? 'Contact'}';
                    return ListTile(
                      onTap: () => _openConv(fpInt(c['id']), u),
                      leading: CircleAvatar(
                        backgroundColor: FpColors.soft,
                        child: Text(_initials(name), style: TextStyle(color: FpColors.navy, fontWeight: FontWeight.w700)),
                      ),
                      title: Text(name, style: TextStyle(fontWeight: unread > 0 ? FontWeight.w800 : FontWeight.w600)),
                      subtitle: Text(
                        last == null ? 'Nouvelle discussion' : '${last['mine'] == true ? 'Vous : ' : ''}${last['text'] ?? ''}',
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(color: unread > 0 ? FpColors.ink : Colors.black54),
                      ),
                      trailing: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        crossAxisAlignment: CrossAxisAlignment.end,
                        children: [
                          Text(_when('${c['updated_at'] ?? ''}'), style: TextStyle(fontSize: 12, color: Colors.black45)),
                          if (unread > 0) ...[
                            SizedBox(height: 4),
                            Container(
                              padding: EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                              decoration: BoxDecoration(color: FpColors.red, borderRadius: BorderRadius.circular(99)),
                              child: Text('$unread', style: TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.w700)),
                            ),
                          ],
                        ],
                      ),
                    );
                  }),
                ],
              ),
            ),
    );
  }
}
