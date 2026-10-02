# Guest ordering — 90-minute dry run

Staff play the guests, using their own phones.
Text in bold is copied exactly from the screen.

## Before you start (10 minutes)
- One manager runs the list. One person writes the results.
- Use Table 1, Table 2 and one checked-in test room (Room 7 below).
- Print the QR card for each one. Scan each once.
- Waiter (Emeka), second waiter (Udeme), bartender (Blessing), chef, receptionist (Ada) and a porter are on shift.
- Menu items used: Jollof Rice, Egusi Soup, Fried Rice, Coke, Beer.
- Every kiosk and display: tap **Tap to enable order sounds**.

## Scenarios

| # | Scenario | Who plays guest | Steps | Expected result | Who checks | ✅/❌ | Notes |
|---|---|---|---|---|---|---|---|
| 1 | Table order, full loop | Udeme | Scan Table 1. Order 1 Jollof Rice and 2 Coke. Tap **Send to waiter**. Emeka taps **Accept** + PIN. Chef cooks. Blessing taps **MARK READY** on the guest card. | Kitchen ticket appears. Coke card (red GUEST strip) in the bar queue. After **MARK READY**, guest **Bill** shows both items. | Manager | | |
| 2 | Two phones, one bill | Udeme + Ada | Both scan Table 1. Each orders one item. Emeka accepts both. | Both phones show the same bill and total. | Manager | | |
| 3a | Guest cancels early | Udeme | Order a Coke. Tap **Cancel request** before anyone accepts. | Card disappears from the kiosk. Nothing on the bill. | Emeka | | |
| 3b | Staff cancels a drink | Udeme | Order a Beer. Emeka accepts. Before the bar marks it ready, Emeka taps **Cancel these drinks**, types a reason, PIN. | Drink leaves the bar screen. Not on the bill. | Blessing | | |
| 4 | Bar gives fewer | Udeme | Order 3 Beer. Accept. Blessing taps **−**, sets 1, picks **Out of stock**, **Confirm**, then **MARK READY**. | Guest sees 1 Beer and "Out of stock". Bill shows 1 Beer. | Manager | | |
| 5 | No bartender on shift | Udeme | Blessing's shift is NOT started. Order a Coke. Emeka accepts. Wait 5 minutes. Then Blessing starts the shift and taps **MARK READY**. | Red banner on the bar screen. **Bar shift not started** on the kiosk. Manager gets an alert at 5 minutes. **MARK READY** works after the shift starts. | Manager | | |
| 6 | Two bartender shifts | — | Leave an old bartender shift open. Start a second one. Tap **MARK READY** on a guest card. | Amber banner. **Who is marking this ready?** with both names. Manager alert. | Manager | | |
| 7 | Ending a shift with drinks waiting | Udeme | Order a Beer. Emeka accepts. Do not mark it ready. Emeka taps **End Shift**. | Blocked. **Hand over your guest tables first** appears. Udeme takes it with **Hand over** + their PIN. | Manager | | |
| 8 | "Same guests?" | Udeme | Put an unpaid staff order on Table 2. Scan Table 2 and order. Emeka taps **Accept**. Try **Yes, same guests**. Repeat on a fresh table with **No, different**. | Yes: the old items join the guest bill. No: they stay off it. | Manager | | |
| 9 | Split, claim, pay | Udeme | Open **Bill**. Tap **Split the bill**: try **Equal** and **By items**. Tap **Pay this amount**, then **I've paid** with a name. Emeka opens the table, **Split by method (e.g. part cash, part transfer)**. | Transfer line is pre-filled with the claim and name. Cash fills the rest. **Confirm · settle** pays the table. | Cashier | | |
| 10 | Full payment screen refused | — | Emeka opens a guest table and tries the full payment screen. | "Guest QR table — use Mark Paid." Nothing is paid. | Manager | | |
| 11 | Move table | Udeme | Emeka taps **Move table**, picks Table 2, PIN. Udeme refreshes the Table 1 page. | Bill moves to Table 2. Old page says "Your table moved to Table 2". | Manager | | |
| 12 | Close table | Udeme | With money unpaid, tap **Close table**. Then pay. Tap **Close table** again. Udeme orders again. | First close refused with the list. Second close works. New order starts a new bill. | Manager | | |
| 13 | Another round | Udeme | After a Beer is marked ready, tap **Another round**. | Beer goes into the cart. Nothing is sent until **Send to waiter**. | Emeka | | |
| 14 | Call waiter | Udeme | Tap **Call waiter**, then Ice. Emeka taps **On my way**. | Blue card on the kiosk. Guest sees "Waiter is on the way 🚶". | Manager | | |
| 15 | Price lock | Udeme | Order Jollof Rice. Manager changes its price. Emeka accepts. | Bill shows the OLD price. | Cashier | | |
| 16 | Sold out | Udeme | Chef taps **Sold out**, signs in, marks Egusi Soup. Then a waiter tries with their PIN. | Egusi Soup leaves the guest menu within about 1½ minutes. Waiter PIN gets "Not allowed". | Chef | | |
| 17 | Room order on WhatsApp | Ada (as guest) | Scan Room 7. Order 1 Fried Rice, 2 Coke. Tap **Send order on WhatsApp**. Receptionist matches the Ref, taps **Approve**, adds the phone, ticks **Agreed to receive specials**. Chef cooks, Blessing taps **MARK READY**. Receptionist **Send with…** the porter, then **Delivered**. | WhatsApp opens with the order and Ref. Guest sees "On the way with …", then "Delivered". Room bill shows both items. | Manager | | |
| 18 | Room order without WhatsApp | Ada | Tap **Order without WhatsApp**. | Card shows **No WhatsApp — call the room**. Receptionist calls, then approves. | Manager | | |
| 19 | Refused drinks | Ada | Blessing marks Coke for Room 7 ready. Dispatch. Receptionist taps **Refused** with a reason. Bring bottles to the bar. Blessing taps **Returned ✓**. Manager approves in **Delivery Refusals**. | Guest sees "Refused — being reviewed". After approval the room bill drops. Coke back in stock. | Manager | | |
| 20 | Refused food | Ada | Deliver Fried Rice. **Refused**. Manager taps **Approve — reverse the charge**. | Room bill drops. Food recorded as waste. No ingredients go back. | Storekeeper | | |
| 21 | Stranger's phone | Udeme | Scan Room 7 on a phone that never ordered. Open **Bill**. | "Your bill appears after your first order is approved." No amounts shown. | Manager | | |
| 22 | Checkout with a pending order | Ada | Order on Room 7. Do not approve. Settle the room bill. Check out. | The pending order is cancelled automatically. | Receptionist | | |
| 23 | Regenerate a QR | — | Manager taps **Regenerate** on Table 2, types a reason, **Replace QR code**. Scan the old card. | Old card shows "no longer valid". New card works. | Manager | | |
| 24 | Sounds blocked | — | Reload the waiter kiosk. Do not tap anything. | **Sound off** shows. Tap **Tap to enable order sounds** and it goes. | Emeka | | |

## How to record problems
For every ❌, write down:
1. The scenario number.
2. A screenshot from the phone or screen.
3. Who saw it.
4. The time it happened.

Send the list to the owner the same day.
