import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../config/theme.dart';
import '../../providers/session_provider.dart';
import '../../services/api_client.dart';
import '../../services/features_service.dart';
import '../../widgets/fp_ui.dart';
import 'chat_list_screen.dart';
import '../../l10n/l10n.dart';

/// Centre de notifications (§11) : historique consultable par profil
/// (argent reçu, paiements, cadeaux, float, sécurité, levée de plafond…).
class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key});

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  final _service = FeaturesService();
  List<Map<String, dynamic>> _items = [];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await _service.notifications();
      if (!mounted) return;
      setState(() {
        _items = ((d['data'] ?? []) as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
        _loading = false;
      });
    } catch (e) {
      if (mounted) {
        setState(() => _loading = false);
        fpSnack(context, apiErrorMessage(e), error: true);
      }
    }
  }

  Future<void> _readAll() async {
    await _service.markAllRead();
    if (!mounted) return;
    context.read<SessionProvider>().refreshUser();
    _load();
  }

  IconData _icon(String? type) => switch (type) {
        'money_received' || 'refund_received' || 'cash_in' => Icons.south_west_rounded,
        'money_sent' || 'withdrawal' || 'cash_out' => Icons.north_east_rounded,
        'payment_received' || 'payment_sent' => Icons.storefront,
        'gift_received' || 'gift_claimed' || 'gift_reminder' || 'gift_expired' => Icons.redeem,
        'split_request' || 'split_settled' || 'split_declined' => Icons.call_split,
        'new_device' || 'pin_changed' || 'account_blocked' => Icons.shield_outlined,
        'limit_raised' || 'kyc_rejected' => Icons.verified_user_outlined,
        'float_request_status' || 'float_topup' || 'low_float' || 'commission' => Icons.account_balance_wallet_outlined,
        'payment_request' => Icons.shopping_bag_outlined,
        'chat_message' => Icons.chat_bubble_outline_rounded,
        _ => Icons.notifications_none,
      };

  Color _color(String? severity) => switch (severity) {
        'success' => FpColors.success,
        'warning' => Color(0xFFA16207),
        'critical' => FpColors.danger,
        _ => FpColors.navy,
      };

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(tr('Notifications')),
        actions: [IconButton(tooltip: tr('Tout marquer comme lu'), icon: Icon(Icons.done_all), onPressed: _readAll)],
      ),
      body: _loading
          ? Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: _items.isEmpty
                  ? ListView(children: [FpEmpty('Aucune notification.', icon: Icons.notifications_off_outlined)])
                  : ListView.separated(
                      itemCount: _items.length,
                      separatorBuilder: (_, __) => Divider(height: 1),
                      itemBuilder: (_, i) {
                        final n = _items[i];
                        final unread = n['read_at'] == null;
                        return ListTile(
                          tileColor: unread ? Color(0xFFF0F4FF) : null,
                          leading: CircleAvatar(
                            backgroundColor: _color(n['severity']?.toString()).withOpacity(.12),
                            child: Icon(_icon(n['type']?.toString()), color: _color(n['severity']?.toString())),
                          ),
                          title: Text('${n['title']}', style: TextStyle(fontWeight: unread ? FontWeight.w700 : FontWeight.w500)),
                          subtitle: Text([if (n['body'] != null) '${n['body']}', fpDate(n['created_at'])].join('\n')),
                          onTap: () async {
                            if (unread) {
                              _service.markRead(fpInt(n['id'])).catchError((_) {});
                              setState(() => n['read_at'] = DateTime.now().toIso8601String());
                            }
                            if (n['type'] == 'chat_message' && context.mounted) {
                              Navigator.push(context, MaterialPageRoute(builder: (_) => ChatListScreen()));
                            }
                          },
                        );
                      },
                    ),
            ),
    );
  }
}
